<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Contract\QueryClassifierContractTest;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;
use ZtdQuery\Rewrite\QueryKind;

#[CoversClass(SqliteQueryGuard::class)]
#[UsesClass(SqliteParser::class)]
final class SqliteQueryGuardTest extends QueryClassifierContractTest
{
    protected function classify(string $sql): ?QueryKind
    {
        return (new SqliteQueryGuard(new SqliteParser()))->classify($sql);
    }

    protected function selectSql(): string
    {
        return 'SELECT * FROM users';
    }

    protected function insertSql(): string
    {
        return "INSERT INTO users (name) VALUES ('Alice')";
    }

    protected function updateSql(): string
    {
        return "UPDATE users SET name = 'Bob' WHERE id = 1";
    }

    protected function deleteSql(): string
    {
        return 'DELETE FROM users WHERE id = 1';
    }

    protected function createTableSql(): string
    {
        return 'CREATE TABLE test (id INTEGER PRIMARY KEY)';
    }

    protected function dropTableSql(): string
    {
        return 'DROP TABLE test';
    }

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
        $this->expectException(\RuntimeException::class);
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
        $guard->assertAllowed("INSERT INTO t (id) VALUES (1)");
        self::addToAssertionCount(1);
    }

    public function testAssertAllowedForCreateTable(): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        $guard->assertAllowed('CREATE TABLE t (id INTEGER)');
        self::addToAssertionCount(1);
    }
}
