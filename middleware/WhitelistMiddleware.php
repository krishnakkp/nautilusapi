<?php
// backend/middleware/WhitelistMiddleware.php

class WhitelistMiddleware {

    public static function handle(): void {
        $origin = Request::origin();

        // Apply CORS headers
        if ($origin) {
            if (self::isAllowed($origin)) {
                header("Access-Control-Allow-Origin: $origin");
                header("Access-Control-Allow-Credentials: true");
            } else {
                // Still need to send CORS headers even on rejection for preflight
                header("Access-Control-Allow-Origin: null");
            }
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');

        // Handle preflight
        if (Request::method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Block requests from unlisted origins (skip check for same-origin / no origin)
        if ($origin) {
            try {
                if (!self::isAllowed($origin)) {
                    Response::error('Origin not whitelisted', 403);
                    exit;
                }
            } catch (Throwable $e) {
                Response::error('Database connection failed', 503);
                exit;
            }
        }
    }

    private static function isAllowed(string $origin): bool {
        $row = Database::queryOne(
            'SELECT id FROM whitelisted_urls WHERE origin = ? AND is_active = 1 LIMIT 1',
            [$origin]
        );
        return $row !== null;
    }
}
