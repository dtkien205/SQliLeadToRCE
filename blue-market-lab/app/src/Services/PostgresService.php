<?php

declare(strict_types=1);

namespace App\Services;

final class PostgresService
{
    /** @var array<string, resource> */
    private static array $connections = [];

    public function __construct(private readonly string $role = 'app')
    {
    }

    public function query(string $sql)
    {
        $result = @pg_query($this->connection(), $sql);
        if ($result === false) {
            throw new \RuntimeException(pg_last_error($this->connection()) ?: 'PostgreSQL query failed.');
        }

        return $result;
    }

    public function queryAll(string $sql): array
    {
        $result = $this->query($sql);
        if ($result === true) {
            return [];
        }

        return pg_fetch_all($result) ?: [];
    }

    public function queryOne(string $sql): ?array
    {
        $rows = $this->queryAll($sql);
        return $rows[0] ?? null;
    }

    public function params(string $sql, array $params): array
    {
        $result = @pg_query_params($this->connection(), $sql, $params);
        if ($result === false) {
            throw new \RuntimeException(pg_last_error($this->connection()) ?: 'PostgreSQL parameterized query failed.');
        }

        return pg_fetch_all($result) ?: [];
    }

    public function paramsOne(string $sql, array $params): ?array
    {
        $rows = $this->params($sql, $params);
        return $rows[0] ?? null;
    }

    public function executeParams(string $sql, array $params): void
    {
        $result = @pg_query_params($this->connection(), $sql, $params);
        if ($result === false) {
            throw new \RuntimeException(pg_last_error($this->connection()) ?: 'PostgreSQL execute failed.');
        }
    }

    public function tableExists(string $table): bool
    {
        $row = $this->paramsOne(
            'SELECT to_regclass($1) AS name',
            ['public.' . $table]
        );

        return !empty($row['name']);
    }

    public function connection()
    {
        if (isset(self::$connections[$this->role])) {
            return self::$connections[$this->role];
        }

        $config = \app_config('pg');
        $userConfig = $config['users'][$this->role] ?? $config['users']['app'];
        $connectionString = sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            $config['host'],
            $config['port'],
            $config['db'],
            $userConfig['user'],
            $userConfig['password']
        );

        $connection = @pg_connect($connectionString);
        if ($connection === false) {
            throw new \RuntimeException('Could not connect to PostgreSQL with role ' . $this->role . '.');
        }

        self::$connections[$this->role] = $connection;
        return $connection;
    }
}
