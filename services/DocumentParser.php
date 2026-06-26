<?php
// backend/services/DocumentParser.php

class DocumentParser {

    /**
     * Parse PDF using pdftotext (poppler) CLI or fallback PHP parsing.
     * Returns array: [page_number => text]
     */
    public function parsePdf(string $path): array {
        // Prefer pdftotext (poppler) if available
        if ($this->commandExists('pdftotext')) {
            return $this->parsePdfWithPoppler($path);
        }

        // Fallback: pure PHP PDF text extraction (no binary deps)
        return $this->parsePdfPhp($path);
    }

    /**
     * Parse DOCX using PHP ZipArchive + XML parsing (no library needed).
     */
    public function parseDocx(string $path): array {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open DOCX: $path");
        }

        $xml  = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) {
            throw new RuntimeException("Cannot read document.xml from DOCX");
        }

        // Strip XML namespaces for simpler parsing
        $xml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xml);
        $xml = preg_replace('/<w:(\w+)/i', '<$1', $xml);
        $xml = preg_replace('/<\/w:(\w+)/i', '</$1', $xml);
        $xml = preg_replace('/<(\w+):(\w+)/i', '<$2', $xml);
        $xml = preg_replace('/<\/(\w+):(\w+)/i', '</$2', $xml);

        $dom = new DOMDocument();
        @$dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $paragraphs = $xpath->query('//p');

        $pages = [];
        $pageNum = 1;
        $pageText = '';
        $parasInPage = 0;
        $parasPerPage = 30; // Approximate

        foreach ($paragraphs as $para) {
            // Collect all text nodes
            $texts = $xpath->query('.//t', $para);
            $line  = '';
            foreach ($texts as $t) {
                $line .= $t->textContent;
            }

            // Check for page break
            $breaks = $xpath->query('.//lastRenderedPageBreak|.//pageBreakBefore', $para);
            if ($breaks->length > 0 && $pageText) {
                $pages[$pageNum] = trim($pageText);
                $pageNum++;
                $pageText    = '';
                $parasInPage = 0;
            }

            if (trim($line)) {
                $pageText    .= $line . "\n";
                $parasInPage++;

                if ($parasInPage >= $parasPerPage) {
                    $pages[$pageNum] = trim($pageText);
                    $pageNum++;
                    $pageText    = '';
                    $parasInPage = 0;
                }
            }
        }

        if (trim($pageText)) {
            $pages[$pageNum] = trim($pageText);
        }

        return $pages ?: [1 => 'No text content extracted'];
    }

    /**
     * Split text into overlapping chunks of ~N words.
     */
    public function chunkText(string $text, int $chunkSize = 500, int $overlap = 50): array {
        $words  = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $total  = count($words);
        $chunks = [];
        $step   = max(1, $chunkSize - $overlap);

        for ($i = 0; $i < $total; $i += $step) {
            $slice   = array_slice($words, $i, $chunkSize);
            $chunks[] = implode(' ', $slice);
            if ($i + $chunkSize >= $total) break;
        }

        return $chunks ?: [trim($text)];
    }

    /**
     * Extract keywords from the last 10% of pages (annexure / index / glossary).
     */
    public function extractKeywords(array $pages): string {
        if (empty($pages)) return '';

        $total     = count($pages);
        $startPage = max(1, (int) ceil($total * 0.9));
        $tail      = '';

        foreach ($pages as $num => $text) {
            if ($num >= $startPage) $tail .= $text . ' ';
        }

        // Find bold/heading-like terms (lines that are short and capitalized)
        $lines    = explode("\n", $tail);
        $keywords = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line || strlen($line) > 80) continue;
            // Heading-like: first letter uppercase, no sentence punctuation
            if (preg_match('/^[A-Z][^.?!]{2,60}$/', $line)) {
                $keywords[] = $line;
            }
        }

        return implode(', ', array_unique(array_slice($keywords, 0, 100)));
    }

    // ── Private methods ────────────────────────────────────────────

    private function parsePdfWithPoppler(string $path): array {
        $tmpBase = sys_get_temp_dir() . '/nautilus_pdf_' . uniqid();
        $cmd     = sprintf('pdftotext -layout %s %s.txt 2>/dev/null', escapeshellarg($path), escapeshellarg($tmpBase));
        exec($cmd, $out, $code);

        $txtFile = $tmpBase . '.txt';
        if ($code !== 0 || !file_exists($txtFile)) {
            throw new RuntimeException("pdftotext failed for: $path");
        }

        $text = file_get_contents($txtFile);
        unlink($txtFile);

        // pdftotext uses \f (form feed) as page separator
        $rawPages = explode("\f", $text);
        $pages    = [];

        foreach ($rawPages as $i => $page) {
            $trimmed = trim($page);
            if ($trimmed) {
                $pages[$i + 1] = $trimmed;
            }
        }

        return $pages ?: [1 => 'No text extracted'];
    }

    private function parsePdfPhp(string $path): array {
        // Minimal pure-PHP PDF text extraction
        // Works on standard text PDFs; scanned PDFs will return minimal text
        $content = file_get_contents($path);
        if (!$content) throw new RuntimeException("Cannot read PDF: $path");

        $pages    = [];
        $pageNum  = 1;

        // Extract stream contents
        preg_match_all('/stream(.*?)endstream/s', $content, $streams);
        $allText = '';

        foreach ($streams[1] as $stream) {
            $stream = trim($stream);

            // Try zlib decompress
            if (substr($stream, 0, 2) === "\x78\x9c" || substr($stream, 0, 2) === "\x78\x01" || substr($stream, 0, 2) === "\x78\xda") {
                $decoded = @gzuncompress($stream);
                if ($decoded !== false) {
                    $allText .= $this->extractPdfText($decoded) . "\n";
                }
            } else {
                $allText .= $this->extractPdfText($stream) . "\n";
            }
        }

        // Split into pseudo-pages (no page boundary info in this mode)
        $lines    = array_filter(explode("\n", $allText), 'trim');
        $perPage  = 40;
        $chunks   = array_chunk(array_values($lines), $perPage);

        foreach ($chunks as $i => $chunk) {
            $text = implode("\n", $chunk);
            if (trim($text)) $pages[$i + 1] = $text;
        }

        return $pages ?: [1 => 'No extractable text found (may be a scanned PDF)'];
    }

    private function extractPdfText(string $stream): string {
        // Extract text between BT/ET markers
        preg_match_all('/BT(.*?)ET/s', $stream, $blocks);
        $lines = [];

        foreach ($blocks[1] as $block) {
            // Match Tj, TJ, and ' operators
            preg_match_all('/\(([^)]*)\)\s*Tj|\[([^\]]*)\]\s*TJ/', $block, $texts);

            foreach ($texts[1] as $t) {
                if (trim($t)) $lines[] = $this->decodePdfString($t);
            }
            foreach ($texts[2] as $t) {
                // TJ array: extract string elements
                preg_match_all('/\(([^)]*)\)/', $t, $tj);
                foreach ($tj[1] as $s) {
                    if (trim($s)) $lines[] = $this->decodePdfString($s);
                }
            }
        }

        return implode(' ', $lines);
    }

    private function decodePdfString(string $s): string {
        // Handle basic PDF escape sequences
        $s = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)'], ["\n", "\r", "\t", '(', ')'], $s);
        return $s;
    }

    private function commandExists(string $cmd): bool {
        $result = shell_exec("which $cmd 2>/dev/null");
        return !empty(trim($result ?? ''));
    }
}
