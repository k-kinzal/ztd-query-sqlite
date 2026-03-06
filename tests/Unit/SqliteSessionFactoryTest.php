<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZtdQuery\Config\ZtdConfig;
use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Connection\StatementInterface;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\SqliteSchemaReflector;
use ZtdQuery\Platform\Sqlite\SqliteSessionFactory;
use ZtdQuery\Platform\Sqlite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\UpdateTransformer;
use ZtdQuery\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SqliteSessionFactory::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
#[UsesClass(SqliteMutationResolver::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SqliteQueryGuard::class)]
#[UsesClass(SqliteRewriter::class)]
#[UsesClass(SqliteSchemaParser::class)]
#[UsesClass(SqliteSchemaReflector::class)]
#[UsesClass(DeleteTransformer::class)]
#[UsesClass(InsertTransformer::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(SqliteTransformer::class)]
#[UsesClass(UpdateTransformer::class)]
final class SqliteSessionFactoryTest extends TestCase
{
    public function testCreateReturnsSession(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $config = new ZtdConfig();
        $factory = new SqliteSessionFactory();
        $session = $factory->create($connection, $config);

        self::assertInstanceOf(Session::class, $session);
    }

    public function testCreateWithExistingTablesRegistersDefinitions(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $config = new ZtdConfig();
        $factory = new SqliteSessionFactory();
        $session = $factory->create($connection, $config);

        self::assertInstanceOf(Session::class, $session);

        $plan = $session->rewrite('SELECT * FROM users');
        self::assertStringContainsString('WITH', $plan->sql());
    }

    public function testCreateWithUnparseableSchemaSkipsTable(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'bad', 'sql' => 'not valid sql'],
            ['name' => 'good', 'sql' => 'CREATE TABLE good (id INTEGER PRIMARY KEY)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $config = new ZtdConfig();
        $factory = new SqliteSessionFactory();
        $session = $factory->create($connection, $config);

        self::assertInstanceOf(Session::class, $session);
    }
}
