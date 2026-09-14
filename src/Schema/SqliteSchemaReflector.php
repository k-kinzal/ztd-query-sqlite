<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema;

use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Platform\SchemaReflector;
use ZtdQuery\Platform\ViewReflector;

/**
 * Fetches SQLite schema information via sqlite_master and PRAGMA queries.
 * @visibility public
 * @example Reflect an empty connection
 *     $connection = new class implements \ZtdQuery\Connection\ConnectionInterface { public function query(string $sql): \ZtdQuery\Connection\StatementInterface|false { return false; } };
 *     $reflector = new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaReflector($connection);
 *     $reflector->reflectAll() // => []
 */
final class SqliteSchemaReflector implements SchemaReflector, ViewReflector
{
    private ConnectionInterface $connection;

    /**
     * Binds the dependencies used by this operation.
     * @visibility public
     * @example Bind a connection to schema reflection
     *     $connection = new class implements \ZtdQuery\Connection\ConnectionInterface { public function query(string $sql): \ZtdQuery\Connection\StatementInterface|false { return false; } };
     *     $reflector = new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaReflector($connection);
     *     $reflector->getCreateStatement("missing") // => null
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * {@inheritDoc}
     * @visibility public
     * @example Return null when a table is absent
     *     $connection = new class implements \ZtdQuery\Connection\ConnectionInterface { public function query(string $sql): \ZtdQuery\Connection\StatementInterface|false { return false; } };
     *     $reflector = new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaReflector($connection);
     *     $reflector->getCreateStatement("missing") // => null
     */
    public function getCreateStatement(string $tableName): ?string
    {
        $stmt = $this->connection->query(
            'SELECT sql FROM ('
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
     * @visibility public
     * @example Return no tables when the connection yields none
     *     $connection = new class implements \ZtdQuery\Connection\ConnectionInterface { public function query(string $sql): \ZtdQuery\Connection\StatementInterface|false { return false; } };
     *     $reflector = new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaReflector($connection);
     *     $reflector->reflectAll() // => []
     */
    public function reflectAll(): array
    {
        $stmt = $this->connection->query(
            'SELECT name, sql FROM ('
            . 'SELECT name, sql, 0 AS precedence FROM sqlite_temp_master '
            . "WHERE type='table' AND name NOT LIKE 'sqlite_%' UNION ALL "
            . 'SELECT name, sql, 1 AS precedence FROM sqlite_master '
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

    /**
     * {@inheritDoc}
     * @visibility public
     * @example Return no views when the connection yields none
     *     $connection = new class implements \ZtdQuery\Connection\ConnectionInterface { public function query(string $sql): \ZtdQuery\Connection\StatementInterface|false { return false; } };
     *     $reflector = new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaReflector($connection);
     *     $reflector->reflectViews() // => []
     */
    public function reflectViews(): array
    {
        $stmt = $this->connection->query(
            'SELECT name, sql FROM (SELECT name, sql, 0 AS precedence FROM sqlite_temp_master '
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
            $definition = (new View\SqliteViewDefinitionParser())->fromCreateStatement($createSql);
            if ($definition !== null) {
                $definitions[$viewName] = $definition;
            }
        }

        return $definitions;
    }
}
