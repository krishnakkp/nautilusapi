<?php
// backend/middleware/AuthMiddleware.php

class AuthMiddleware {

    /**
     * Require a valid JWT. Returns the user array or halts.
     */
    public static function require(): array {
        $token = Request::bearerToken();
        if (!$token) {
            Response::error('Authentication required', 401);
            exit;
        }

        $payload = JWT::verify($token);
        if (!$payload) {
            Response::error('Invalid or expired token', 401);
            exit;
        }

        // Check token still in DB (not revoked) and user is active
        $row = Database::queryOne(
            'SELECT u.id, u.name, u.email, u.role, u.is_active
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ? AND t.expires_at > NOW()',
            [$token]
        );

        if (!$row || !$row['is_active']) {
            Response::error('Authentication required', 401);
            exit;
        }

        // Touch last_used_at
        Database::execute('UPDATE api_tokens SET last_used_at = NOW() WHERE token = ?', [$token]);

        return $row;
    }

    /**
     * Require admin role.
     */
    public static function requireAdmin(): array {
        $user = self::require();
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden: admin only', 403);
            exit;
        }
        return $user;
    }
}
