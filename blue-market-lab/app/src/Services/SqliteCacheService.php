<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class SqliteCacheService
{
    private PDO $pdo;

    public function __construct()
    {
        $path = (string) \app_config('paths.sqlite');
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS search_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                keyword TEXT,
                created_at TEXT
            )'
        );
    }

    // vuln
    public function logSearch(string $keyword): void
    {
        $sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $keyword . "', datetime('now'))";
        $this->pdo->exec($sql);
    }

    public function recentSearches(int $limit = 8): array
    {
        $stmt = $this->pdo->prepare('SELECT keyword, created_at FROM search_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
