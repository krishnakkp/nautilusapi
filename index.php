<?php
// backend/index.php — Main entry point
// All requests routed through this file via .htaccess

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Never expose errors to client

// ── Autoload core classes ──────────────────────────────────────────
$baseDir = __DIR__;

require_once $baseDir . '/core/Database.php';
require_once $baseDir . '/core/Response.php';
require_once $baseDir . '/core/Request.php';
require_once $baseDir . '/core/Router.php';
require_once $baseDir . '/core/JWT.php';
require_once $baseDir . '/core/Logger.php';
require_once $baseDir . '/middleware/AuthMiddleware.php';
require_once $baseDir . '/middleware/WhitelistMiddleware.php';
require_once $baseDir . '/middleware/RateLimiter.php';

// ── Controllers ────────────────────────────────────────────────────
require_once $baseDir . '/api/v1/auth/AuthController.php';
require_once $baseDir . '/api/v1/chat/ChatController.php';
require_once $baseDir . '/api/v1/documents/DocumentController.php';
require_once $baseDir . '/api/v1/admin/AdminController.php';

// ── Global middleware ─────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    Logger::error('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::error('Internal server error', 500);
});

// Apply whitelist + CORS first
WhitelistMiddleware::handle();

// ── Router ─────────────────────────────────────────────────────────
$router = new Router();

// Get method and URI
$method = Request::method();
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Strip /api/v1 prefix if present
$uri = preg_replace('#^/api/v1#', '', $uri) ?: '/';
$uri = rtrim($uri, '/') ?: '/';

// ── AUTH ──────────────────────────────────────────────────────────
$router->post('/auth/register',        [AuthController::class, 'register']);
$router->post('/auth/login',           [AuthController::class, 'login']);
$router->post('/auth/logout',          [AuthController::class, 'logout']);
$router->post('/auth/verify-email',    [AuthController::class, 'verifyEmail']);
$router->get ('/auth/verify-email',    [AuthController::class, 'verifyEmail']);
$router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/auth/reset-password',  [AuthController::class, 'resetPassword']);
$router->get ('/auth/me',              [AuthController::class, 'me']);

// ── CHAT ──────────────────────────────────────────────────────────
$router->post  ('/chat/ask',                  [ChatController::class, 'ask']);
$router->get   ('/chat/sessions',             [ChatController::class, 'sessions']);
$router->get   ('/chat/sessions/:id',         [ChatController::class, 'session']);
$router->delete('/chat/sessions/:id',         [ChatController::class, 'deleteSession']);
$router->get   ('/chat/faqs',                 [ChatController::class, 'faqs']);
$router->get   ('/chat/categories',           [ChatController::class, 'categories']);
$router->post  ('/chat/submit-query',         [ChatController::class, 'submitQuery']);

// ── DOCUMENTS (Admin) ─────────────────────────────────────────────
$router->get   ('/admin/documents',           [DocumentController::class, 'index']);
$router->post  ('/admin/documents',           [DocumentController::class, 'upload']);
$router->get   ('/admin/documents/:id',       [DocumentController::class, 'show']);
$router->delete('/admin/documents/:id',       [DocumentController::class, 'delete']);
$router->post  ('/admin/documents/:id/reparse',[DocumentController::class,'reparse']);

// ── ADMIN ─────────────────────────────────────────────────────────
$router->get   ('/admin/categories',          [AdminController::class, 'categoriesIndex']);
$router->post  ('/admin/categories',          [AdminController::class, 'categoriesCreate']);
$router->put   ('/admin/categories/:id',      [AdminController::class, 'categoriesUpdate']);
$router->delete('/admin/categories/:id',      [AdminController::class, 'categoriesDelete']);

$router->get   ('/admin/users',               [AdminController::class, 'usersIndex']);
$router->get   ('/admin/users/:id',           [AdminController::class, 'usersShow']);
$router->put   ('/admin/users/:id/toggle',    [AdminController::class, 'usersToggle']);

$router->get   ('/admin/whitelist',           [AdminController::class, 'whitelistIndex']);
$router->post  ('/admin/whitelist',           [AdminController::class, 'whitelistCreate']);
$router->delete('/admin/whitelist/:id',       [AdminController::class, 'whitelistDelete']);
$router->put   ('/admin/whitelist/:id/toggle',[AdminController::class, 'whitelistToggle']);

$router->get   ('/admin/queries',             [AdminController::class, 'queriesIndex']);
$router->post  ('/admin/queries/:id/answer',  [AdminController::class, 'queriesAnswer']);
$router->get   ('/admin/questions',           [AdminController::class, 'allQuestions']);

$router->get   ('/admin/metrics',             [AdminController::class, 'metrics']);

// ── Health check ──────────────────────────────────────────────────
$router->get('/health', function() {
    Response::success(['status' => 'ok', 'timestamp' => time()]);
});

// ── Dispatch ──────────────────────────────────────────────────────
$router->dispatch($method, $uri);
