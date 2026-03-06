<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Platform\SchemaReflector;

/**
 * Fetches SQLite schema information via sqlite_master and PRAGMA queries.
 */
final class SqliteSchemaReflector implements SchemaReflector
{
    private ConnectionInterface $connection;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateStatement(string $tableName): ?string
    {
        $stmt = $this->connection->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'"
        );
        if ($stmt === false) {
            return null;
        }

        $rows = $stmt->fetchAll();
        if ($rows === [] || !isset($rows[0]['sql']) || !is_string($rows[0]['sql'])) {
            return null;
        }

        return $rows[0]['sql'];
    }

    /**
     * {@inheritDoc}
     */
    public function reflectAll(): array
    {
        $stmt = $this->connection->query(
            "SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        if ($stmt === false) {
            return [];
        }

        $tables = $stmt->fetchAll();
        $result = [];

        foreach ($tables as $row) {
            $tableName = $row['name'] ?? null;
            $createSql = $row['sql'] ?? null;

            if (!is_string($tableName) || $tableName === '' || !is_string($createSql) || $createSql === '') {
                continue;
            }

            $result[$tableName] = $createSql;
        }

        return $result;
    }
}
