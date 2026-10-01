<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Controllers\SearchController;

require __DIR__ . '/../src/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    match ([$method, $path]) {
        ['GET', '/'] => (new HomeController())->index(),
        ['GET', '/products'] => (new SearchController())->products(),
        ['GET', '/search'] => (new SearchController())->search(),
        ['GET', '/sellers'] => (new ProfileController())->sellers(),
        ['GET', '/profile'] => (new ProfileController())->show(),
        ['POST', '/profile/update'] => (new ProfileController())->update(),
        ['GET', '/login'] => (new AuthController())->loginForm(),
        ['POST', '/login'] => (new AuthController())->login(),
        ['GET', '/register'] => (new AuthController())->registerForm(),
        ['POST', '/register'] => (new AuthController())->register(),
        ['POST', '/logout'] => (new AuthController())->logout(),
        ['GET', '/admin'] => (new AdminController())->dashboard(),
        ['GET', '/admin/media'] => (new AdminController())->media(),
        ['POST', '/admin/media'] => (new AdminController())->uploadMedia(),
        ['GET', '/admin/templates'] => (new AdminController())->templates(),
        ['POST', '/admin/templates'] => (new AdminController())->saveTemplate(),
        ['GET', '/admin/reports'] => (new ReportController())->system(),
        default => (new HomeController())->notFound(),
    };
} catch (Throwable $exception) {
    http_response_code(500);
    (new HomeController())->error($exception);
}
