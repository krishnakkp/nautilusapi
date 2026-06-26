<?php
// backend/api/v1/chat/ChatController.php

require_once __DIR__ . '/../../../services/LLMService.php';
require_once __DIR__ . '/../../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../../middleware/RateLimiter.php';

class ChatController {

    public function ask(array $params = []): void {
        $user = AuthMiddleware::require();
        $cfg  = require __DIR__ . '/../../../config/config.php';

        // Rate limit: 10 questions per minute per user
        RateLimiter::check("chat:{$user['id']}", $cfg['rate_limit']['chat_per_minute'], 60);

        $errors = Request::validate(['question' => 'required|min:3|max:2000']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
            return;
        }

        $question   = trim(strip_tags(Request::post('question')));
        $sessionId  = Request::post('session_id');
        $categoryId = Request::post('category_id') ? (int) Request::post('category_id') : null;

        // Create or validate session
        if ($sessionId) {
            $session = Database::queryOne(
                'SELECT id FROM chat_sessions WHERE id = ? AND user_id = ?',
                [$sessionId, $user['id']]
            );
            if (!$session) {
                Response::error('Session not found', 404);
                return;
            }
        } else {
            $title     = mb_substr($question, 0, 80);
            $sessionId = Database::insert(
                'INSERT INTO chat_sessions (user_id, title) VALUES (?, ?)',
                [$user['id'], $title]
            );
        }

        // ── Step 1: FAQ cache check ──────────────────────────────
        $normalised = $this->normaliseQuestion($question);
        $hash       = hash('sha256', $normalised);
        $faq        = Database::queryOne(
            'SELECT * FROM faqs WHERE question_hash = ?',
            [$hash]
        );

        if ($faq && $faq['ask_count'] >= $cfg['faq']['cache_threshold'] && $faq['canonical_answer']) {
            // Serve cached answer
            $msgId = $this->persistMessage(
                $sessionId, $user['id'], $question,
                $faq['canonical_answer'], $categoryId, 1, 0.99
            );
            $this->upsertFaq($hash, $question, $faq['canonical_answer'], $categoryId);

            Response::success([
                'session_id' => (int) $sessionId,
                'message_id' => (int) $msgId,
                'answer'     => $faq['canonical_answer'],
                'sources'    => [],
                'is_answered'=> true,
                'from_cache' => true,
            ]);
            return;
        }

        // ── Step 2: Auto-detect category from keywords ───────────
        if (!$categoryId) {
            $categoryId = $this->detectCategory($question);
        }

        // ── Step 3: Retrieve relevant document chunks ─────────────
        $chunks = $this->retrieveChunks($question, $categoryId, $cfg['llm']['context_chunks']);

        if (empty($chunks)) {
            // No documents found at all
            $answer  = 'I could not find any relevant information in the knowledge base for your question.';
            $msgId   = $this->persistMessage($sessionId, $user['id'], $question, $answer, $categoryId, 0, 0.0);
            $queryId = Database::insert(
                'INSERT INTO unanswered_queries (message_id, user_id, question) VALUES (?, ?, ?)',
                [$msgId, $user['id'], $question]
            );
            Response::success([
                'session_id'   => (int) $sessionId,
                'message_id'   => (int) $msgId,
                'answer'       => $answer,
                'sources'      => [],
                'is_answered'  => false,
                'query_id'     => (int) $queryId,
            ]);
            return;
        }

        // ── Step 4: LLM call ──────────────────────────────────────
        try {
            $llm    = new LLMService();
            $result = $llm->answer($question, $chunks);
        } catch (Exception $e) {
            Logger::error('LLM failed: ' . $e->getMessage());
            Response::error('AI service temporarily unavailable. Please try again.', 503);
            return;
        }

        // ── Step 5: Persist ───────────────────────────────────────
        $msgId = $this->persistMessage(
            $sessionId, $user['id'], $question,
            $result['answer'], $categoryId,
            $result['answered'] ? 1 : 0,
            $result['confidence']
        );

        // Save sources
        foreach ($result['sources'] as $src) {
            Database::insert(
                'INSERT INTO message_sources (message_id, document_id, page_number, relevance_rank) VALUES (?,?,?,?)',
                [$msgId, $src['document_id'], $src['page_number'], $src['relevance_rank']]
            );
        }

        // If unanswered, queue for admin
        $queryId = null;
        if (!$result['answered']) {
            $queryId = Database::insert(
                'INSERT INTO unanswered_queries (message_id, user_id, question) VALUES (?, ?, ?)',
                [$msgId, $user['id'], $question]
            );
        }

        // Update FAQ
        $this->upsertFaq($hash, $question, $result['answer'], $categoryId);

        Response::success([
            'session_id'  => (int) $sessionId,
            'message_id'  => (int) $msgId,
            'answer'      => $result['answer'],
            'sources'     => $result['sources'],
            'is_answered' => $result['answered'],
            'query_id'    => $queryId ? (int) $queryId : null,
            'from_cache'  => false,
        ]);
    }

    public function sessions(array $params = []): void {
        $user = AuthMiddleware::require();
        ['page' => $page, 'perPage' => $perPage, 'offset' => $offset] = Request::paginate();

        $total = Database::queryOne(
            'SELECT COUNT(*) AS c FROM chat_sessions WHERE user_id = ?',
            [$user['id']]
        )['c'];

        $rows = Database::query(
            'SELECT id, title, created_at, updated_at FROM chat_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT ? OFFSET ?',
            [$user['id'], $perPage, $offset]
        );

        Response::paginated($rows, (int) $total, $page, $perPage);
    }

    public function session(array $params): void {
        $user = AuthMiddleware::require();
        $id   = (int) ($params['id'] ?? 0);

        $session = Database::queryOne(
            'SELECT * FROM chat_sessions WHERE id = ? AND user_id = ?',
            [$id, $user['id']]
        );

        if (!$session) {
            Response::error('Session not found', 404);
            return;
        }

        $messages = Database::query(
            'SELECT m.*,
                    (SELECT JSON_ARRAYAGG(
                        JSON_OBJECT(
                            "document_id", s.document_id,
                            "document_title", d.title,
                            "page_number", s.page_number,
                            "relevance_rank", s.relevance_rank
                        )
                    ) FROM message_sources s JOIN documents d ON d.id = s.document_id WHERE s.message_id = m.id) AS sources
             FROM chat_messages m
             WHERE m.session_id = ?
             ORDER BY m.created_at ASC',
            [$id]
        );

        foreach ($messages as &$msg) {
            $msg['sources'] = json_decode($msg['sources'] ?? '[]', true);
        }

        Response::success(['session' => $session, 'messages' => $messages]);
    }

    public function deleteSession(array $params): void {
        $user = AuthMiddleware::require();
        $id   = (int) ($params['id'] ?? 0);

        $affected = Database::execute(
            'DELETE FROM chat_sessions WHERE id = ? AND user_id = ?',
            [$id, $user['id']]
        );

        if (!$affected) {
            Response::error('Session not found', 404);
            return;
        }

        Response::success(null, 'Session deleted');
    }

    public function faqs(array $params = []): void {
        AuthMiddleware::require();

        $categoryId = Request::get('category_id');
        $limit      = min(50, (int) (Request::get('limit') ?? 20));

        $sql    = 'SELECT f.*, c.name AS category_name FROM faqs f LEFT JOIN categories c ON c.id = f.category_id';
        $binds  = [];

        if ($categoryId) {
            $sql   .= ' WHERE f.category_id = ?';
            $binds[] = (int) $categoryId;
        }

        $sql .= ' ORDER BY f.ask_count DESC LIMIT ?';
        $binds[] = $limit;

        Response::success(Database::query($sql, $binds));
    }

    public function categories(array $params = []): void {
        AuthMiddleware::require();
        $rows = Database::query(
            'SELECT id, name, slug, description, parent_id, sort_order
             FROM categories
             ORDER BY sort_order ASC, name ASC'
        );
        Response::success($rows);
    }

    public function submitQuery(array $params = []): void {
        $user = AuthMiddleware::require();

        $errors = Request::validate(['question' => 'required|min:5|max:2000']);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
            return;
        }

        $question = trim(strip_tags(Request::post('question')));
        $msgId    = Request::post('message_id');

        $id = Database::insert(
            'INSERT INTO unanswered_queries (message_id, user_id, question) VALUES (?, ?, ?)',
            [$msgId ?: null, $user['id'], $question]
        );

        Response::success(['query_id' => (int) $id], 'Query submitted. Admin will respond shortly.', 201);
    }

    // ── Private helpers ───────────────────────────────────────────

    private function normaliseQuestion(string $q): string {
        $q = mb_strtolower($q);
        $q = preg_replace('/[^\w\s]/u', ' ', $q);
        $q = preg_replace('/\s+/', ' ', $q);
        return trim($q);
    }

    private function detectCategory(string $question): ?int {
        $categories = Database::query('SELECT id, name FROM categories');
        $q = mb_strtolower($question);

        foreach ($categories as $cat) {
            if (str_contains($q, mb_strtolower($cat['name']))) {
                return (int) $cat['id'];
            }
        }
        return null;
    }

    private function retrieveChunks(string $question, ?int $categoryId, int $limit): array {
        // Prepare FULLTEXT search terms
        $words  = array_filter(explode(' ', preg_replace('/[^\w\s]/u', '', $question)));
        $ftExpr = implode(' ', array_map(fn($w) => '+' . $w, array_slice($words, 0, 10)));

        $binds = [$ftExpr];

        $categoryJoin = '';
        if ($categoryId) {
            $categoryJoin = 'AND d.category_id = ?';
            $binds[]      = $categoryId;
        }

        $binds[] = $limit;

        $chunks = Database::query(
            "SELECT dc.document_id, dc.page_number, dc.content,
                    d.title,
                    MATCH(dc.content) AGAINST (? IN BOOLEAN MODE) AS score
             FROM document_chunks dc
             JOIN documents d ON d.id = dc.document_id AND d.status = 'ready'
             WHERE MATCH(dc.content) AGAINST (? IN BOOLEAN MODE)
             $categoryJoin
             ORDER BY score DESC
             LIMIT ?",
            array_merge([$ftExpr], $binds)
        );

        // Fallback: widen to full corpus if < 3 results
        if (count($chunks) < 3 && $categoryId) {
            $chunks = Database::query(
                "SELECT dc.document_id, dc.page_number, dc.content,
                        d.title,
                        MATCH(dc.content) AGAINST (? IN BOOLEAN MODE) AS score
                 FROM document_chunks dc
                 JOIN documents d ON d.id = dc.document_id AND d.status = 'ready'
                 WHERE MATCH(dc.content) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY score DESC
                 LIMIT ?",
                [$ftExpr, $ftExpr, $limit]
            );
        }

        return $chunks;
    }

    private function persistMessage(
        int|string $sessionId, int $userId, string $question,
        string $answer, ?int $categoryId, int $isAnswered, float $confidence
    ): string {
        // Save user message
        Database::insert(
            'INSERT INTO chat_messages (session_id, user_id, role, question, category_id, created_at) VALUES (?,?,?,?,?,NOW())',
            [$sessionId, $userId, 'user', $question, $categoryId]
        );

        // Save assistant message
        $id = Database::insert(
            'INSERT INTO chat_messages (session_id, user_id, role, answer, category_id, is_answered, confidence_score, created_at) VALUES (?,?,?,?,?,?,?,NOW())',
            [$sessionId, $userId, 'assistant', $answer, $categoryId, $isAnswered, $confidence]
        );

        // Touch session updated_at
        Database::execute('UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?', [$sessionId]);

        return $id;
    }

    private function upsertFaq(string $hash, string $question, string $answer, ?int $categoryId): void {
        $existing = Database::queryOne('SELECT id, ask_count FROM faqs WHERE question_hash = ?', [$hash]);

        if ($existing) {
            Database::execute(
                'UPDATE faqs SET ask_count = ask_count + 1, last_asked_at = NOW(), canonical_answer = ? WHERE question_hash = ?',
                [$answer, $hash]
            );
        } else {
            Database::insert(
                'INSERT INTO faqs (question_hash, canonical_question, canonical_answer, ask_count, category_id) VALUES (?,?,?,1,?)',
                [$hash, $question, $answer, $categoryId]
            );
        }
    }
}
