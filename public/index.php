<?php

declare(strict_types=1);

// Simple autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $baseDir . $relative . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use App\Config\Env;
use App\Middleware\Cors;
use App\Helpers\Response;
use App\Helpers\Security;

// Load local defaults first, then override them with production values when enabled.
Env::load(__DIR__ . '/../.env');
if (($_ENV['APP_ENV'] ?? 'local') === 'production') {
    Env::load(__DIR__ . '/../.env.production');
}
Security::headers();
Security::assertProductionSecrets();
Cors::handle();

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Strip subdirectory base path (XAMPP: /goodscores/backend/public/...)
// Set APP_BASE_PATH in .env if your folder name differs, e.g. /my-app/backend/public
$basePath = rtrim($_ENV['APP_BASE_PATH'] ?? '/goodscores/backend/public', '/');
if ($basePath !== '' && str_starts_with($uri, $basePath)) {
    $uri = substr($uri, strlen($basePath)) ?: '/';
}

// Also strip optional /api prefix
$uri = preg_replace('#^/api#', '', $uri);
$uri = rtrim($uri, '/') ?: '/';

// Serve uploaded files: /storage/...
if (str_starts_with($uri, '/storage/')) {
    $file = __DIR__ . '/../storage/' . substr($uri, 9);
    // prevent path traversal
    $real = realpath($file);
    $root = realpath(__DIR__ . '/../storage');
    if ($real && $root && str_starts_with($real, $root) && is_file($real)) {
        $mime = mime_content_type($real) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }
    http_response_code(404);
    exit;
}

// Exact routes
$routes = [
    'GET /health'               => ['App\Controllers\HealthController', 'check'],
    'GET /dashboard/stats'      => ['App\Controllers\DashboardController', 'stats'],

    'POST /auth/register'       => ['App\Controllers\AuthController', 'register'],
    'POST /auth/login'          => ['App\Controllers\AuthController', 'login'],
    'GET /auth/me'              => ['App\Controllers\AuthController', 'me'],
    'PUT /auth/settings'        => ['App\Controllers\AuthController', 'updateSettings'],
    'DELETE /auth/account'      => ['App\Controllers\AuthController', 'deleteAccount'],
        'POST /auth/forgot-password' => ['App\Controllers\AuthController', 'forgotPassword'],
        'POST /auth/reset-password'  => ['App\Controllers\AuthController', 'resetPassword'],

    'GET /school/teachers'      => ['App\Controllers\SchoolController', 'teachers'],
    'POST /school/teachers'     => ['App\Controllers\SchoolController', 'createTeacher'],
    'GET /school/info'          => ['App\Controllers\SchoolController', 'info'],
    'PUT /school/info'          => ['App\Controllers\SchoolController', 'update'],

    'GET /headers'              => ['App\Controllers\HeaderController', 'index'],
    'POST /headers'             => ['App\Controllers\HeaderController', 'store'],

    'GET /credits/balance'      => ['App\Controllers\CreditController', 'balance'],
    'GET /credits/transactions' => ['App\Controllers\CreditController', 'transactions'],
    'POST /credits/initialize'  => ['App\Controllers\CreditController', 'initializePayment'],
    'POST /credits/verify'      => ['App\Controllers\CreditController', 'verifyPayment'],
        'POST /credits/authorize-export' => ['App\Controllers\CreditController', 'authorizeExport'],

    'GET /meta/subjects'        => ['App\Controllers\MetaController', 'subjects'],
    'GET /meta/classes'         => ['App\Controllers\MetaController', 'classes'],
    'GET /meta/terms'           => ['App\Controllers\MetaController', 'terms'],
    'GET /meta/topics'          => ['App\Controllers\MetaController', 'topics'],
    'POST /meta/topics'         => ['App\Controllers\MetaController', 'createTopic'],
    'POST /meta/subjects'       => ['App\Controllers\MetaController', 'createSubject'],
    'POST /meta/classes'        => ['App\Controllers\MetaController', 'createClass'],

    'GET /questions'            => ['App\Controllers\QuestionController', 'index'],
    'POST /questions'           => ['App\Controllers\QuestionController', 'store'],
    'GET /diagrams'             => ['App\Controllers\DiagramController', 'index'],
    'POST /diagrams'            => ['App\Controllers\DiagramController', 'store'],
        'GET /passages'             => ['App\Controllers\PassageController', 'index'],
        'POST /passages'            => ['App\Controllers\PassageController', 'store'],

    'GET /papers'               => ['App\Controllers\PaperController', 'index'],
    'POST /papers'              => ['App\Controllers\PaperController', 'store'],

    'POST /ocr'                 => ['App\Controllers\OcrController', 'extract'],
    'POST /ai/questions/generate' => ['App\Controllers\AiController', 'generateQuestions'],
    'POST /ai/diagram/generate' => ['App\Controllers\AiController', 'generateDiagram'],
    'GET /backup/questions'       => ['App\Controllers\BackupController', 'restoreQuestions'],
    'GET /backup/papers'          => ['App\Controllers\BackupController', 'restorePapers'],
    'POST /backup/questions'      => ['App\Controllers\BackupController', 'questions'],
    'GET /backup/status'          => ['App\Controllers\BackupController', 'status'],
];

// Parameterised routes
$paramRoutes = [
    ['GET',    '#^/questions/(\d+)$#',           'App\Controllers\QuestionController', 'show'],
    ['PUT',    '#^/questions/(\d+)$#',           'App\Controllers\QuestionController', 'update'],
    ['DELETE', '#^/questions/(\d+)$#',           'App\Controllers\QuestionController', 'destroy'],
    ['POST',   '#^/questions/(\d+)/images$#',    'App\Controllers\QuestionController', 'uploadImage'],
    ['POST',   '#^/questions/(\d+)/generate-illustration$#', 'App\Controllers\QuestionController', 'generateIllustration'],
    ['PUT',    '#^/diagrams/(\d+)$#',            'App\Controllers\DiagramController', 'update'],
    ['DELETE', '#^/diagrams/(\d+)$#',            'App\Controllers\DiagramController', 'destroy'],
    ['PUT',    '#^/meta/subjects/(\d+)$#',       'App\Controllers\MetaController', 'updateSubject'],
    ['DELETE', '#^/meta/subjects/(\d+)$#',       'App\Controllers\MetaController', 'deleteSubject'],
    ['PUT',    '#^/meta/classes/(\d+)$#',        'App\Controllers\MetaController', 'updateClass'],
    ['DELETE', '#^/meta/classes/(\d+)$#',        'App\Controllers\MetaController', 'deleteClass'],

    ['GET',    '#^/papers/(\d+)$#',              'App\Controllers\PaperController', 'show'],
    ['PUT',    '#^/papers/(\d+)$#',              'App\Controllers\PaperController', 'update'],
    ['DELETE', '#^/papers/(\d+)$#',              'App\Controllers\PaperController', 'destroy'],
    ['POST',   '#^/papers/(\d+)/export$#',       'App\Controllers\PaperController', 'export'],
    ['POST',   '#^/school/logo$#',                'App\Controllers\SchoolController', 'uploadLogo'],
    ['POST',   '#^/headers/(\d+)/logo$#',         'App\Controllers\HeaderController', 'uploadLogo'],

    ['DELETE', '#^/school/teachers/(\d+)$#',     'App\Controllers\SchoolController', 'removeTeacher'],
    ['PUT',    '#^/school/teachers/(\d+)$#',     'App\Controllers\SchoolController', 'updateTeacher'],
    ['PATCH',  '#^/school/teachers/(\d+)$#',     'App\Controllers\SchoolController', 'setTeacherActive'],
    ['PUT',    '#^/headers/(\d+)$#',             'App\Controllers\HeaderController', 'update'],
    ['DELETE', '#^/headers/(\d+)$#',             'App\Controllers\HeaderController', 'destroy'],
];

$key = $method . ' ' . $uri;

if (isset($routes[$key])) {
    [$class, $action] = $routes[$key];
    (new $class())->$action();
    exit;
}

foreach ($paramRoutes as [$m, $pattern, $class, $action]) {
    if ($m === $method && preg_match($pattern, $uri, $matches)) {
        array_shift($matches);
        (new $class())->$action(...array_map('intval', $matches));
        exit;
    }
}

Response::error('Not Found', 404);
