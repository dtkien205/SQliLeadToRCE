<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PostgresService;

final class ReportController extends BaseController
{
    public function system(): void
    {
        $this->requireAdmin();

        $type = (string) ($_GET['type'] ?? 'health');
        $db = new PostgresService('app');
        $error = null;

        try {
            $templates = $db->params(
                'SELECT type, label, description, query_name
                 FROM report_templates
                 WHERE type = $1
                 ORDER BY id',
                [$type]
            );
        } catch (\Throwable $exception) {
            $templates = [];
            $error = 'The report could not be loaded.';
        }

        $stats = $this->collectStats();

        $this->render('admin/reports', [
            'title' => 'Reports',
            'type' => $type,
            'templates' => $templates,
            'stats' => $stats,
            'commandOutput' => [],
            'error' => $error,
        ]);
    }

    private function collectStats(): array
    {
        $db = new PostgresService();

        return [
            'Users' => $db->paramsOne('SELECT count(*) AS value FROM users', [])['value'] ?? 0,
            'Products' => $db->paramsOne('SELECT count(*) AS value FROM products', [])['value'] ?? 0,
            'Orders' => $db->paramsOne('SELECT count(*) AS value FROM orders', [])['value'] ?? 0,
            'Worker' => 'online',
        ];
    }
}
