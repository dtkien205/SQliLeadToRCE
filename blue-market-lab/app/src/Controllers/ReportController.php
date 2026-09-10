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
        $db = new PostgresService($this->isFixed() ? 'app' : 'report');
        $error = null;

        try {
            if ($this->isFixed()) {
                $templates = $db->params(
                    'SELECT type, label, description, query_name
                     FROM report_templates
                     WHERE type = $1
                     ORDER BY id',
                    [$type]
                );
            } else {
                $sql = "SELECT type, label, description, query_name
                        FROM report_templates
                        WHERE type = '" . $type . "'
                        ORDER BY id";
                $templates = $db->queryAll($sql);
            }
        } catch (\Throwable $exception) {
            $templates = [];
            $error = $this->isFixed() ? 'The report could not be loaded.' : $exception->getMessage();
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

    private function readCommandOutput(PostgresService $db): array
    {
        if ($this->isFixed()) {
            return [];
        }

        try {
            if (!$db->tableExists('cmd_output')) {
                return [];
            }

            return $db->queryAll('SELECT line FROM cmd_output LIMIT 50');
        } catch (\Throwable) {
            return [];
        }
    }
}
