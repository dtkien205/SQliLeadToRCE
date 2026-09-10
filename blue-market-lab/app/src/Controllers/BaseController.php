<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

abstract class BaseController
{
    protected function render(string $view, array $params = []): void
    {
        $viewFile = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException("View {$view} was not found.");
        }

        $config = \app_config();
        $mode = \app_config('app_mode', 'vulnerable');
        $currentUser = \current_user();
        $flash = \flash();

        extract($params, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        require dirname(__DIR__) . '/Views/layout.php';
    }

    protected function mode(): string
    {
        return (string) \app_config('app_mode', 'vulnerable');
    }

    protected function isFixed(): bool
    {
        return $this->mode() === 'fixed';
    }

    protected function requireAdmin(): void
    {
        if (!\is_admin()) {
            \flash('Please sign in with an admin account to open the admin workspace.');
            \redirect('/login');
        }
    }

    public function error(Throwable $exception): void
    {
        $this->render('error', [
            'title' => 'System Error',
            'message' => $this->isFixed()
                ? 'The system could not complete the request.'
                : $exception->getMessage(),
            'details' => $this->isFixed() ? null : $exception->getTraceAsString(),
        ]);
    }

    public function notFound(): void
    {
        http_response_code(404);
        $this->render('error', [
            'title' => 'Not Found',
            'message' => 'The page you requested does not exist in BlueMarket.',
            'details' => null,
        ]);
    }
}
