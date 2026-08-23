<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Connection\StatementInterface;
use ZtdQuery\Platform\Sqlite\SqliteSchemaReflector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SqliteSchemaReflector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteViewDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteSchemaReflectorTest extends TestCase
{
    public function testReflectViewsReturnsEmptyWhenQueryFails(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(false);

        self::assertSame([], (new SqliteSchemaReflector($connection))->reflectViews());
    }

    public function testReflectViewsPrefersTemporaryAndSkipsMalformedRows(): void
    {
        $statement = self::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => null, 'sql' => 'CREATE VIEW ignored AS SELECT 1'],
            ['name' => '', 'sql' => 'CREATE VIEW ignored AS SELECT 1'],
            ['name' => 'non_string', 'sql' => null],
            ['name' => 'invalid', 'sql' => 'CREATE VIEW invalid'],
            ['name' => 'active_users', 'sql' => 'CREATE TEMP VIEW active_users AS SELECT * FROM temp.users'],
            ['name' => 'active_users', 'sql' => 'CREATE VIEW active_users AS SELECT * FROM main.users'],
            ['name' => 'all_users', 'sql' => 'CREATE VIEW all_users AS SELECT * FROM main.users'],
        ]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('query')->with(
            "SELECT name, sql FROM (SELECT name, sql, 0 AS precedence FROM sqlite_temp_master "
            . "WHERE type='view' UNION ALL SELECT name, sql, 1 AS precedence FROM sqlite_master "
            . "WHERE type='view') ORDER BY precedence, name",
        )->willReturn($statement);

        $definitions = (new SqliteSchemaReflector($connection))->reflectViews();

        self::assertSame(['active_users', 'all_users'], array_keys($definitions));
        self::assertSame('SELECT * FROM temp.users', $definitions['active_users']->query);
    }

    public function testGetCreateStatementReturnsNullWhenQueryFails(): void
    {
        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(false);

        $reflector = new SqliteSchemaReflector($connection);
        self::assertNull($reflector->getCreateStatement('users'));
    }

    public function testGetCreateStatementReturnsNullWhenNoRows(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        self::assertNull($reflector->getCreateStatement('users'));
    }

    public function testGetCreateStatementReturnsSql(): void
    {
        $createSql = 'CREATE TABLE users (id INTEGER PRIMARY KEY)';
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([['sql' => $createSql]]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        self::assertSame($createSql, $reflector->getCreateStatement('users'));
    }

    public function testGetCreateStatementReturnsNullWhenSqlNotString(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([['sql' => null]]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        self::assertNull($reflector->getCreateStatement('users'));
    }

    public function testReflectAllReturnsEmptyWhenQueryFails(): void
    {
        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(false);

        $reflector = new SqliteSchemaReflector($connection);
        self::assertSame([], $reflector->reflectAll());
    }

    public function testReflectAllReturnsTables(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => 'CREATE TABLE users (id INTEGER PRIMARY KEY)'],
            ['name' => 'orders', 'sql' => 'CREATE TABLE orders (id INTEGER PRIMARY KEY)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();

        self::assertCount(2, $result);
        self::assertArrayHasKey('users', $result);
        self::assertArrayHasKey('orders', $result);
    }

    public function testReflectAllPrefersTemporaryTableWhenNamesCollide(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'staging', 'sql' => 'CREATE TABLE staging (temporary_value TEXT)'],
            ['name' => 'staging', 'sql' => 'CREATE TABLE staging (persistent_value TEXT)'],
            ['name' => 'after', 'sql' => 'CREATE TABLE after (id INTEGER)'],
        ]);
        $connection = static::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('query')->with(
            "SELECT name, sql FROM (SELECT name, sql, 0 AS precedence FROM sqlite_temp_master "
            . "WHERE type='table' AND name NOT LIKE 'sqlite_%' UNION ALL "
            . "SELECT name, sql, 1 AS precedence FROM sqlite_master "
            . "WHERE type='table' AND name NOT LIKE 'sqlite_%') ORDER BY precedence, name",
        )->willReturn($statement);

        $result = (new SqliteSchemaReflector($connection))->reflectAll();

        self::assertSame('CREATE TABLE staging (temporary_value TEXT)', $result['staging']);
        self::assertSame('CREATE TABLE after (id INTEGER)', $result['after']);
    }

    public function testReflectAllSkipsInvalidRows(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => 'CREATE TABLE users (id INTEGER PRIMARY KEY)'],
            ['name' => '', 'sql' => 'CREATE TABLE empty (id INTEGER)'],
            ['name' => 'orders', 'sql' => ''],
            ['name' => null, 'sql' => 'CREATE TABLE null_name (id INTEGER)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();

        self::assertCount(1, $result);
        self::assertArrayHasKey('users', $result);
    }

    public function testGetCreateStatementEscapesSingleQuotesInTableName(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([['sql' => 'CREATE TABLE t (id INTEGER)']]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->getCreateStatement("user's");
        self::assertSame('CREATE TABLE t (id INTEGER)', $result);
    }

    public function testReflectAllReturnsSqlValues(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertSame('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)', $result['users']);
    }

    public function testReflectAllSkipsRowsWithMissingName(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['sql' => 'CREATE TABLE t (id INTEGER)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertSame([], $result);
    }

    public function testReflectAllSkipsRowsWithNonStringSql(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => 42],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertSame([], $result);
    }

    public function testReflectAllSkipsRowsWithNonStringName(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 42, 'sql' => 'CREATE TABLE t (id INTEGER)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertSame([], $result);
    }

    public function testReflectAllMultipleValidTables(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => 'CREATE TABLE users (id INTEGER)'],
            ['name' => 'orders', 'sql' => 'CREATE TABLE orders (id INTEGER)'],
            ['name' => 'items', 'sql' => 'CREATE TABLE items (id INTEGER)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertCount(3, $result);
        self::assertSame('CREATE TABLE users (id INTEGER)', $result['users']);
        self::assertSame('CREATE TABLE orders (id INTEGER)', $result['orders']);
        self::assertSame('CREATE TABLE items (id INTEGER)', $result['items']);
    }

    public function testReflectAllSkipsRowWithEmptyNameAndValidSql(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => '', 'sql' => 'CREATE TABLE t (id INTEGER)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertSame([], $result);
    }

    public function testReflectAllSkipsRowWithValidNameAndEmptySql(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => ''],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertSame([], $result);
    }

    public function testGetCreateStatementWithSingleQuoteInTableName(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['sql' => "CREATE TABLE \"it's\" (id INTEGER)"],
        ]);

        $connection = static::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('query')->with(
            "SELECT sql FROM (SELECT sql, 0 AS precedence FROM sqlite_temp_master WHERE type='table' AND name='it''s' "
            . "UNION ALL SELECT sql, 1 AS precedence FROM sqlite_master WHERE type='table' AND name='it''s') "
            . 'ORDER BY precedence LIMIT 1',
        )->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->getCreateStatement("it's");
        self::assertSame("CREATE TABLE \"it's\" (id INTEGER)", $result);
    }

    public function testReflectAllKeepsOnlyValidRowsAmongInvalid(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => null, 'sql' => 'CREATE TABLE a (id INTEGER)'],
            ['name' => 'good', 'sql' => 'CREATE TABLE good (id INTEGER)'],
            ['name' => 'bad', 'sql' => null],
            ['name' => '', 'sql' => 'CREATE TABLE empty (id INTEGER)'],
            ['name' => 'also_good', 'sql' => 'CREATE TABLE also_good (id INTEGER)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertCount(2, $result);
        self::assertArrayHasKey('good', $result);
        self::assertArrayHasKey('also_good', $result);
    }

    public function testGetCreateStatementReturnsNullWhenSqlKeyMissing(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['other_key' => 'value'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        self::assertNull($reflector->getCreateStatement('users'));
    }

    public function testReflectAllSkipsMissingSqlKey(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $reflector = new SqliteSchemaReflector($connection);
        $result = $reflector->reflectAll();
        self::assertSame([], $result);
    }
}
