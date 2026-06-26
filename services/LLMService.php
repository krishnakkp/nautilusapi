<?php
// backend/services/LLMService.php

class LLMService {
    private string $provider;
    private string $apiKey;
    private string $model;
    private array  $cfg;

    public function __construct() {
        $cfg = require __DIR__ . '/../config/config.php';
        $this->cfg      = $cfg['llm'];
        $this->provider = $this->cfg['provider'];
        $this->apiKey   = $this->cfg['api_key'];
        $this->model    = $this->cfg['model'][$this->provider] ?? 'claude-sonnet-4-6';
    }

    /**
     * Generate a knowledge-base answer from provided chunks.
     * Returns ['answer' => string, 'sources' => [...], 'confidence' => float, 'answered' => bool]
     */
    public function answer(string $question, array $chunks): array {
        $context = $this->buildContext($chunks);
        $prompt  = $this->buildPrompt($question, $context);

        $raw = match ($this->provider) {
            'openai' => $this->callOpenAI($prompt),
            'gemini' => $this->callGemini($prompt),
            default  => $this->callClaude($prompt),
        };

        return $this->parseResponse($raw, $chunks);
    }

    private function buildContext(array $chunks): string {
        $parts = [];
        foreach ($chunks as $i => $chunk) {
            $parts[] = "[SOURCE {$i}] Document: \"{$chunk['title']}\" | Page: {$chunk['page_number']}\n{$chunk['content']}";
        }
        return implode("\n\n---\n\n", $parts);
    }

    private function buildPrompt(string $question, string $context): string {
        return <<<PROMPT
You are a knowledge assistant for Nautilus Shipping. Your job is to answer questions using ONLY the document excerpts provided below. Do NOT use any external knowledge.

Rules:
- Answer in 2–4 concise sentences.
- Always cite which SOURCE numbers you used, e.g. [SOURCE 0], [SOURCE 2].
- If the answer is NOT found in the provided sources, respond exactly with: "UNANSWERED: I could not find this information in the available documents."
- Never make up information.

--- DOCUMENT EXCERPTS ---
{$context}
--- END EXCERPTS ---

Question: {$question}

Answer:
PROMPT;
    }

    private function callClaude(string $prompt): string {
        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->cfg['max_tokens'],
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        return $this->httpPost(
            'https://api.anthropic.com/v1/messages',
            $body,
            [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            fn($r) => $r['content'][0]['text'] ?? ''
        );
    }

    private function callOpenAI(string $prompt): string {
        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => $this->cfg['max_tokens'],
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        return $this->httpPost(
            'https://api.openai.com/v1/chat/completions',
            $body,
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            fn($r) => $r['choices'][0]['message']['content'] ?? ''
        );
    }

    private function callGemini(string $prompt): string {
        $body = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
        ]);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        return $this->httpPost(
            $url,
            $body,
            ['Content-Type: application/json'],
            fn($r) => $r['candidates'][0]['content']['parts'][0]['text'] ?? ''
        );
    }

    private function httpPost(string $url, string $body, array $headers, callable $extractor): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            Logger::error("LLM API error ({$this->provider}) HTTP $httpCode: $error | Response: $response");
            throw new RuntimeException("LLM API call failed: HTTP $httpCode");
        }

        $decoded = json_decode($response, true);
        return $extractor($decoded) ?? '';
    }

    private function parseResponse(string $raw, array $chunks): array {
        $raw = trim($raw);

        // Check unanswered
        if (str_starts_with($raw, 'UNANSWERED:')) {
            return [
                'answer'     => trim(substr($raw, 11)),
                'sources'    => [],
                'confidence' => 0.0,
                'answered'   => false,
            ];
        }

        // Extract cited source indices
        preg_match_all('/\[SOURCE (\d+)\]/i', $raw, $matches);
        $citedIndices = array_unique(array_map('intval', $matches[1] ?? []));

        // Build sources from cited chunks
        $sources    = [];
        $confidence = count($citedIndices) > 0 ? min(0.95, 0.6 + count($citedIndices) * 0.1) : 0.4;

        foreach ($citedIndices as $i) {
            if (isset($chunks[$i])) {
                $sources[] = [
                    'document_id'    => $chunks[$i]['document_id'],
                    'document_title' => $chunks[$i]['title'],
                    'page_number'    => $chunks[$i]['page_number'],
                    'relevance_rank' => $i + 1,
                ];
            }
        }

        // Clean source tags from answer text
        $answer = preg_replace('/\[SOURCE \d+\]/i', '', $raw);
        $answer = preg_replace('/\s{2,}/', ' ', trim($answer));

        return [
            'answer'     => $answer,
            'sources'    => $sources,
            'confidence' => round($confidence, 3),
            'answered'   => true,
        ];
    }
}
