<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite;

use RuntimeException;
use ZtdQuery\Rewrite\QueryKind;

/**
 * Classifies SQL and enforces ZTD write-protection rules for SQLite.
 * @visibility public
 * @example Identify simulated writes
 *     $guard = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard(new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser());
 *     $guard->classify("DELETE FROM users") // => \ZtdQuery\Rewrite\QueryKind::WRITE_SIMULATED
 */
final class SqliteQueryGuard
{
    private \ZtdQuery\Platform\Sqlite\Sql\SqliteParser $parser;

    /**
     * Binds the dependencies used by this operation.
     * @visibility public
     * @example Use the SQLite parser for classification
     *     $guard = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard(new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser());
     *     $guard->classify("SELECT 1") // => \ZtdQuery\Rewrite\QueryKind::READ
     */
    public function __construct(\ZtdQuery\Platform\Sqlite\Sql\SqliteParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Classify a SQL string into READ/WRITE_SIMULATED/DDL_SIMULATED or null if unsupported.
     * @visibility public
     * @example Distinguish reads, virtual DDL and unsupported operations
     *     $guard = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard(new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser());
     *     $guard->classify("CREATE TABLE users(id INTEGER)") // => \ZtdQuery\Rewrite\QueryKind::DDL_SIMULATED
     *     $guard->classify("VACUUM") // => null
     */
    public function classify(string $sql): ?QueryKind
    {
        if (\ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement::isSafe($sql)) {
            return QueryKind::READ;
        }
        if (\ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement::isSafe($sql)) {
            return QueryKind::READ;
        }
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
     * @throws RuntimeException When the SQL is not allowed.
     *
     * @visibility public
     * @example Reject operations that would modify the physical database
     *     $guard = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard(new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser());
     *     $guard->assertAllowed('SELECT 1');
     *     $guard->assertAllowed('VACUUM') // throws \RuntimeException: ZTD Write Protection
     */
    public function assertAllowed(string $sql): void
    {
        $kind = $this->classify($sql);
        if ($kind === null) {
            throw new RuntimeException('ZTD Write Protection: Unsupported or unsafe SQL statement.');
        }
    }
}
