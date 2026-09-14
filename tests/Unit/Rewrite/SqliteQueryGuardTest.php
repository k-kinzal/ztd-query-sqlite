<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Rewrite\QueryKind;

#[CoversClass(SqliteQueryGuard::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Attach\AttachTokens::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\AssignmentColumnParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\AssignmentParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\ValueListParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Insert\InsertClauseParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\OpaqueSqlSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\RelationSourceParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\StatementClassifier::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\StatementStructure::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\TargetTableParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Update\UpdateClauseParser::class)]
#[UsesClass(SqliteInMemoryAttachStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class SqliteQueryGuardTest extends \PHPUnit\Framework\TestCase
{
    public function testSelectClassifiesAsRead(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::READ, $guard->classify('SELECT * FROM users'));
    }

    public function testInsertClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify("INSERT INTO users (name) VALUES ('Alice')"));
    }

    public function testUpdateClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify("UPDATE users SET name = 'Bob' WHERE id = 1"));
    }

    public function testDeleteClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify('DELETE FROM users WHERE id = 1'));
    }

    public function testCreateTableClassifiesAsDdlSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::DDL_SIMULATED, $guard->classify('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'));
    }

    public function testDropTableClassifiesAsDdlSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::DDL_SIMULATED, $guard->classify('DROP TABLE users'));
    }

    public function testGarbageInputReturnsNull(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());

        self::assertNull($guard->classify('NOT VALID SQL %%%'));
    }

    public function testReplaceClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify("REPLACE INTO users (id, name) VALUES (1, 'Alice')"));
    }

    public function testInsertOrReplaceClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify("INSERT OR REPLACE INTO users (id, name) VALUES (1, 'Alice')"));
    }

    public function testAlterTableClassifiesAsDdlSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::DDL_SIMULATED, $guard->classify('ALTER TABLE users ADD COLUMN email TEXT'));
    }

    public function testUnsupportedReturnsNull(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertNull($guard->classify('CREATE INDEX idx ON users (name)'));
    }

    public function testClassifiesReadOnlyExplainAsRead(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::READ, $guard->classify('EXPLAIN SELECT * FROM users'));
        self::assertSame(QueryKind::READ, $guard->classify('EXPLAIN QUERY PLAN SELECT * FROM users'));
        self::assertSame(QueryKind::READ, $guard->classify('EXPLAIN QUERY PLAN DELETE FROM users'));
        self::assertNull($guard->classify('EXPLAIN ANALYZE DELETE FROM users'));
    }

    public function testClassifiesOnlyInMemoryAttachAsRead(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::READ, $guard->classify("ATTACH DATABASE ':memory:' AS db2"));
        self::assertNull($guard->classify("ATTACH 'test.sqlite' AS db2"));
    }

    public function testEmptyReturnsNull(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertNull($guard->classify(''));
    }

    public function testCteSelectClassifiesAsRead(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::READ, $guard->classify('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function testAssertAllowedDoesNotThrowForSelect(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        $guard->assertAllowed('SELECT * FROM users');
        self::addToAssertionCount(1);
    }

    public function testAssertAllowedThrowsForUnsupported(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        $this->expectException(RuntimeException::class);
        $guard->assertAllowed('GRANT ALL ON users TO admin');
    }

    public function testClassifyReturnsNullForEmptyString(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        $result = $guard->classify('');
        self::assertNull($result);
    }

    public function testClassifyReturnsNullForCreateIndex(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        $result = $guard->classify('CREATE INDEX idx_name ON users (name)');
        self::assertNull($result);
    }

    public function testSelectLowercaseClassifiesAsRead(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::READ, $guard->classify('select * from users'));
    }

    public function testInsertLowercaseClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify("insert into users (name) values ('Alice')"));
    }

    public function testDeleteLowercaseClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify('delete from users where id = 1'));
    }

    public function testUpdateLowercaseClassifiesAsWriteSimulated(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        self::assertSame(QueryKind::WRITE_SIMULATED, $guard->classify("update users set name = 'x'"));
    }

    public function testAssertAllowedForInsert(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        $guard->assertAllowed('INSERT INTO t (id) VALUES (1)');
        self::addToAssertionCount(1);
    }

    public function testAssertAllowedForCreateTable(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        $guard->assertAllowed('CREATE TABLE t (id INTEGER)');
        self::addToAssertionCount(1);
    }
}
