<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Rewrite\QueryKind;

/**
 * Classifies SQL and enforces ZTD write-protection rules for SQLite.
 */
final class SqliteQueryGuard
{
    private SqliteParser $parser;

    public function __construct(SqliteParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Classify a SQL string into READ/WRITE_SIMULATED/DDL_SIMULATED or null if unsupported.
     */
    public function classify(string $sql): ?QueryKind
    {
        $type = $this->parser->classifyStatement($sql);
        if ($type === null) {
            return null;
        }

        return match ($type) {
            'SELECT' => QueryKind::READ,
            'INSERT', 'UPDATE', 'DELETE' => QueryKind::WRITE_SIMULATED,
            'CREATE_TABLE', 'DROP_TABLE', 'ALTER_TABLE' => QueryKind::DDL_SIMULATED,
            default => null,
        };
    }

    /**
     * Assert that the SQL is allowed by the guard.
     *
     * @throws \RuntimeException When the SQL is not allowed.
     */
    public function assertAllowed(string $sql): void
    {
        $kind = $this->classify($sql);
        if ($kind === null) {
            throw new \RuntimeException('ZTD Write Protection: Unsupported or unsafe SQL statement.');
        }
    }
}
