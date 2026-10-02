<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PostgresService;

final class ReportController extends BaseController
{
    public function system(): void
    {
        $this->requireAdmin();

        // vuln
        $type = (string) ($_GET['type'] ?? 'health');
        $db = new PostgresService('report');
        $error = null;

        // vuln
        try {
            $sql = "SELECT type, label, description, query_name
                    FROM report_templates
                    WHERE type = '" . $type . "'
                    ORDER BY id";
            $templates = $db->queryAll($sql);
        } catch (\Throwable $exception) {
            $templates = [];
            $error = $exception->getMessage();
        }

        $stats = $this->collectStats();
        $commandOutput = $this->readCommandOutput($db);

        $this->render('admin/reports', [
            'title' => 'Reports',
            'type' => $type,
            'templates' => $templates,
            'stats' => $stats,
            'commandOutput' => $commandOutput,
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


    // report
    private function readCommandOutput(PostgresService $db): array
    {
        try {
            if (!$db->tableExists('report_worker_output')) {
                return [];
            }

            return $db->queryAll(
                'SELECT line FROM report_worker_output ORDER BY id DESC LIMIT 50'
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
