<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Platform\SchemaReflector;
use ZtdQuery\Platform\ViewReflector;
use ZtdQuery\Schema\ViewDefinition;

/**
 * Fetches SQLite schema information via sqlite_master and PRAGMA queries.
 */
final class SqliteSchemaReflector implements SchemaReflector, ViewReflector
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
            "SELECT sql FROM ("
            . "SELECT sql, 0 AS precedence FROM sqlite_temp_master WHERE type='table' AND name='"
            . str_replace("'", "''", $tableName)
            . "' UNION ALL SELECT sql, 1 AS precedence FROM sqlite_master WHERE type='table' AND name='"
            . str_replace("'", "''", $tableName)
            . "') ORDER BY precedence LIMIT 1"
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
            "SELECT name, sql FROM ("
            . "SELECT name, sql, 0 AS precedence FROM sqlite_temp_master "
            . "WHERE type='table' AND name NOT LIKE 'sqlite_%' UNION ALL "
            . "SELECT name, sql, 1 AS precedence FROM sqlite_master "
            . "WHERE type='table' AND name NOT LIKE 'sqlite_%'"
            . ') ORDER BY precedence, name'
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
            if (isset($result[$tableName])) {
                continue;
            }

            $result[$tableName] = $createSql;
        }

        return $result;
    }

    /** {@inheritDoc} */
    public function reflectViews(): array
    {
        $stmt = $this->connection->query(
            "SELECT name, sql FROM (SELECT name, sql, 0 AS precedence FROM sqlite_temp_master "
            . "WHERE type='view' UNION ALL SELECT name, sql, 1 AS precedence FROM sqlite_master "
            . "WHERE type='view') ORDER BY precedence, name",
        );
        if ($stmt === false) {
            return [];
        }

        $definitions = [];
        foreach ($stmt->fetchAll() as $row) {
            $viewName = $row['name'] ?? null;
            $createSql = $row['sql'] ?? null;
            if (!is_string($viewName) || $viewName === '' || isset($definitions[$viewName]) || !is_string($createSql)) {
                continue;
            }
            $definition = (new SqliteViewDefinitionParser())->fromCreateStatement($createSql);
            if ($definition !== null) {
                $definitions[$viewName] = $definition;
            }
        }

        return $definitions;
    }
}
