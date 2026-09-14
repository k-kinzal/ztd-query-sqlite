<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Diagnostic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement;

#[CoversClass(SqliteReadOnlyDiagnosticStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class SqliteReadOnlyDiagnosticStatementTest extends TestCase
{
    #[DataProvider('providerStatement')]
    public function testIsSafeClassifiesOnlySafeSqliteDiagnostics(string $sql, bool $expected): void
    {
        self::assertSame($expected, SqliteReadOnlyDiagnosticStatement::isSafe($sql));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providerStatement(): iterable
    {
        yield 'explain select' => ['EXPLAIN SELECT * FROM users', true];
        yield 'query plan' => ['EXPLAIN QUERY PLAN SELECT * FROM users', true];
        yield 'query plan minimum token boundary' => ['EXPLAIN QUERY PLAN SELECT', true];
        yield 'query without plan' => ['EXPLAIN QUERY SELECT * FROM users', false];
        yield 'plan without statement' => ['EXPLAIN QUERY PLAN', false];
        yield 'unsupported analyze form' => ['EXPLAIN ANALYZE DELETE FROM users', false];
        yield 'unsupported analyse spelling' => ['EXPLAIN ANALYSE SELECT * FROM users', false];
        yield 'mysql show' => ['SHOW TABLES', false];
        yield 'mysql describe' => ['DESCRIBE users', false];
        yield 'multiple statements' => ['EXPLAIN SELECT 1; DELETE FROM users', false];
        yield 'empty explain' => ['EXPLAIN', false];
    }
}
