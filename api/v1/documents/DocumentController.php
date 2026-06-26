<?php
// backend/api/v1/documents/DocumentController.php

require_once __DIR__ . '/../../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../../services/DocumentParser.php';

class DocumentController {

    public function upload(array $params = []): void {
        $admin = AuthMiddleware::requireAdmin();
        $cfg   = require __DIR__ . '/../../../config/config.php';

        $file       = Request::file('file');
        $categoryId = (int) (Request::post('category_id') ?? 0);
        $title      = trim(Request::post('title') ?? '');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('File upload failed or no file provided', 400);
            return;
        }

        // Validate MIME (some hosts report DOCX as application/zip)
        $mime = mime_content_type($file['tmp_name']);
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'docx' && in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        $allowed = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($mime, $allowed)) {
            Response::error('Only PDF and DOCX files are allowed', 400);
            return;
        }

        // Validate size
        $maxBytes = $cfg['app']['max_upload_mb'] * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            Response::error("File exceeds maximum size of {$cfg['app']['max_upload_mb']}MB", 400);
            return;
        }

        // Validate category
        if (!Database::queryOne('SELECT id FROM categories WHERE id = ?', [$categoryId])) {
            Response::error('Invalid category', 400);
            return;
        }

        // Build storage path
        $uploadDir = rtrim($cfg['app']['upload_dir'], '/');
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = $mime === 'application/pdf' ? 'pdf' : 'docx';
        $filename = uniqid('doc_', true) . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to save uploaded file', 500);
            return;
        }

        if (!$title) {
            $title = pathinfo($file['name'], PATHINFO_FILENAME);
        }

        // Insert document record
        $docId = Database::insert(
            'INSERT INTO documents (category_id, title, original_filename, storage_path, mime_type, file_size, status, uploaded_by) VALUES (?,?,?,?,?,?,?,?)',
            [$categoryId, $title, $file['name'], $destPath, $mime, $file['size'], 'pending', $admin['id']]
        );

        // Queue parsing job
        Database::insert(
            'INSERT INTO document_jobs (document_id, status) VALUES (?, ?)',
            [$docId, 'queued']
        );

        // Parse immediately so documents are searchable without a background worker
        $this->parseDocument((int) $docId, $destPath, $mime);

        $doc = Database::queryOne(
            'SELECT status, error_message, page_count FROM documents WHERE id = ?',
            [$docId]
        );

        $status  = $doc['status'] ?? 'pending';
        $message = match ($status) {
            'ready' => 'Document uploaded and indexed successfully.',
            'error' => 'Document uploaded but parsing failed: ' . ($doc['error_message'] ?? 'unknown error'),
            default => 'Document uploaded. Parsing in progress.',
        };

        Response::success(
            [
                'document_id' => (int) $docId,
                'status'      => $status,
                'page_count'  => isset($doc['page_count']) ? (int) $doc['page_count'] : null,
                'error'       => $doc['error_message'] ?? null,
            ],
            $message,
            201
        );
    }

    public function index(array $params = []): void {
        AuthMiddleware::requireAdmin();
        ['page' => $page, 'perPage' => $perPage, 'offset' => $offset] = Request::paginate();

        $categoryId = Request::get('category_id');
        $status     = Request::get('status');
        $search     = Request::get('search');

        $where  = ['1=1'];
        $binds  = [];

        if ($categoryId) { $where[] = 'd.category_id = ?'; $binds[] = (int) $categoryId; }
        if ($status)     { $where[] = 'd.status = ?';       $binds[] = $status; }
        if ($search)     { $where[] = 'd.title LIKE ?';     $binds[] = "%$search%"; }

        $whereStr = implode(' AND ', $where);

        $total = Database::queryOne(
            "SELECT COUNT(*) AS c FROM documents d WHERE $whereStr",
            $binds
        )['c'];

        $rows = Database::query(
            "SELECT d.*, c.name AS category_name, u.name AS uploaded_by_name
             FROM documents d
             JOIN categories c ON c.id = d.category_id
             JOIN users u ON u.id = d.uploaded_by
             WHERE $whereStr
             ORDER BY d.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($binds, [$perPage, $offset])
        );

        Response::paginated($rows, (int) $total, $page, $perPage);
    }

    public function show(array $params): void {
        AuthMiddleware::requireAdmin();
        $id  = (int) ($params['id'] ?? 0);
        $doc = Database::queryOne(
            'SELECT d.*, c.name AS category_name FROM documents d JOIN categories c ON c.id = d.category_id WHERE d.id = ?',
            [$id]
        );

        if (!$doc) {
            Response::error('Document not found', 404);
            return;
        }

        $chunkCount = Database::queryOne(
            'SELECT COUNT(*) AS c FROM document_chunks WHERE document_id = ?',
            [$id]
        )['c'];

        $doc['chunk_count'] = (int) $chunkCount;
        Response::success($doc);
    }

    public function delete(array $params): void {
        AuthMiddleware::requireAdmin();
        $id  = (int) ($params['id'] ?? 0);
        $doc = Database::queryOne('SELECT storage_path FROM documents WHERE id = ?', [$id]);

        if (!$doc) {
            Response::error('Document not found', 404);
            return;
        }

        Database::execute('DELETE FROM documents WHERE id = ?', [$id]);

        // Delete physical file
        if (file_exists($doc['storage_path'])) {
            unlink($doc['storage_path']);
        }

        Response::success(null, 'Document deleted');
    }

    public function reparse(array $params): void {
        AuthMiddleware::requireAdmin();
        $id  = (int) ($params['id'] ?? 0);
        $doc = Database::queryOne('SELECT id, storage_path, mime_type FROM documents WHERE id = ?', [$id]);

        if (!$doc) {
            Response::error('Document not found', 404);
            return;
        }

        // Clear existing chunks
        Database::execute('DELETE FROM document_chunks WHERE document_id = ?', [$id]);
        Database::execute("UPDATE documents SET status = 'pending', page_count = NULL WHERE id = ?", [$id]);

        $this->parseDocument((int) $doc['id'], $doc['storage_path'], $doc['mime_type']);

        Response::success(null, 'Re-parse triggered');
    }

    // ── Internal parse ─────────────────────────────────────────────

    private function parseDocument(int $docId, string $path, string $mime): void {
        try {
            Database::execute("UPDATE documents SET status = 'processing' WHERE id = ?", [$docId]);
            Database::execute("UPDATE document_jobs SET status = 'processing', attempts = attempts + 1 WHERE document_id = ?", [$docId]);

            $parser = new DocumentParser();
            $pages  = $mime === 'application/pdf'
                ? $parser->parsePdf($path)
                : $parser->parseDocx($path);

            $this->assertExtractedText($pages);

            $cfg = require __DIR__ . '/../../../config/config.php';
            $chunkSize    = $cfg['llm']['chunk_size'];
            $chunkOverlap = $cfg['llm']['chunk_overlap'];

            foreach ($pages as $pageNum => $text) {
                $chunks = $parser->chunkText($text, $chunkSize, $chunkOverlap);
                foreach ($chunks as $idx => $chunk) {
                    Database::insert(
                        'INSERT INTO document_chunks (document_id, page_number, chunk_index, content) VALUES (?,?,?,?)',
                        [$docId, $pageNum, $idx, $chunk]
                    );
                }
            }

            // Extract keywords from last 10% of pages (index/annexure)
            $keywords = $parser->extractKeywords($pages);

            Database::execute(
                "UPDATE documents SET status = 'ready', page_count = ?, keywords = ? WHERE id = ?",
                [count($pages), $keywords, $docId]
            );
            Database::execute("UPDATE document_jobs SET status = 'done' WHERE document_id = ?", [$docId]);

        } catch (Exception $e) {
            Logger::error("Document parse failed (ID $docId): " . $e->getMessage());
            Database::execute(
                "UPDATE documents SET status = 'error', error_message = ? WHERE id = ?",
                [$e->getMessage(), $docId]
            );
            Database::execute(
                "UPDATE document_jobs SET status = 'failed', error_message = ? WHERE document_id = ?",
                [$e->getMessage(), $docId]
            );
        }
    }

    private function assertExtractedText(array $pages): void {
        $placeholders = [
            'No text content extracted',
            'No text extracted',
            'No extractable text found (may be a scanned PDF)',
        ];

        $totalChars = 0;
        foreach ($pages as $text) {
            $trimmed = trim($text);
            if (in_array($trimmed, $placeholders, true)) {
                throw new RuntimeException(
                    'Could not extract readable text. The file may be a scanned/image PDF — please upload a text-based PDF or DOCX.'
                );
            }
            $totalChars += strlen($trimmed);
        }

        if ($totalChars < 50) {
            throw new RuntimeException('Could not extract enough text from the document to index it.');
        }
    }
}
