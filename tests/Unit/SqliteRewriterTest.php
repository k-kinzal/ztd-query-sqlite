<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Contract\RewriterContractTest;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\UpdateTransformer;
use ZtdQuery\Rewrite\QueryKind;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\CreateTableMutation;
use ZtdQuery\Shadow\Mutation\DeleteMutation;
use ZtdQuery\Shadow\Mutation\DropTableMutation;
use ZtdQuery\Shadow\Mutation\InsertMutation;
use ZtdQuery\Shadow\Mutation\ReplaceMutation;
use ZtdQuery\Shadow\Mutation\UpdateMutation;
use ZtdQuery\Shadow\Mutation\UpsertMutation;
use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Rewrite\SqlRewriter;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(SqliteRewriter::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SqliteQueryGuard::class)]
#[UsesClass(SqliteSchemaParser::class)]
#[UsesClass(SqliteMutationResolver::class)]
#[UsesClass(SqliteTransformer::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(InsertTransformer::class)]
#[UsesClass(UpdateTransformer::class)]
#[UsesClass(DeleteTransformer::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
final class SqliteRewriterTest extends RewriterContractTest
{
    protected function createRewriter(ShadowStore $store, TableDefinitionRegistry $registry): SqlRewriter
    {
        $parser = new SqliteParser();
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);

        return new SqliteRewriter(new SqliteQueryGuard($parser), $store, $registry, $transformer, $mutationResolver, $parser);
    }

    protected function createSchemaParser(): SchemaParser
    {
        return new SqliteSchemaParser();
    }

    protected function selectSql(): string
    {
        return 'SELECT id, name, email FROM users WHERE id = 1';
    }

    protected function insertSql(): string
    {
        return "INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')";
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
        return 'CREATE TABLE orders (id INTEGER PRIMARY KEY, amount REAL)';
    }

    protected function dropTableSql(): string
    {
        return 'DROP TABLE IF EXISTS orders';
    }

    protected function unsupportedSql(): string
    {
        return 'CREATE INDEX idx ON users (name)';
    }

    protected function usersCreateTableSql(): string
    {
        return 'CREATE TABLE users (id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL)';
    }

    public function testSelectReturnsReadKind(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');

        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertNull($plan->mutation());
        self::assertStringContainsString('SELECT', strtoupper($plan->sql()));
    }

    public function testInsertReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(InsertMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
        self::assertMatchesRegularExpression('/^(?:WITH\b|SELECT\b)/i', $plan->sql());
    }

    public function testUpdateReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(UpdateMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
        self::assertMatchesRegularExpression('/^(?:WITH\b|SELECT\b)/i', $plan->sql());
    }

    public function testDeleteReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(DeleteMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
        self::assertMatchesRegularExpression('/^(?:WITH\b|SELECT\b)/i', $plan->sql());
    }

    public function testDeleteFromWithoutWhereReturnsWriteSimulated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users');

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(DeleteMutation::class, $plan->mutation());
    }

    public function testReplaceReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("REPLACE INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(ReplaceMutation::class, $plan->mutation());
    }

    public function testInsertOrReplaceReturnsWriteSimulatedWithReplaceMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("INSERT OR REPLACE INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(ReplaceMutation::class, $plan->mutation());
    }

    public function testInsertOnConflictReturnsUpsertMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com') ON CONFLICT (id) DO UPDATE SET name = excluded.name");

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(UpsertMutation::class, $plan->mutation());
    }

    public function testCreateTableReturnsDdlSimulated(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        self::assertSame(QueryKind::DDL_SIMULATED, $plan->kind());
        self::assertInstanceOf(CreateTableMutation::class, $plan->mutation());
    }

    public function testDropTableReturnsDdlSimulated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DROP TABLE users');

        self::assertSame(QueryKind::DDL_SIMULATED, $plan->kind());
        self::assertInstanceOf(DropTableMutation::class, $plan->mutation());
    }

    public function testUnsupportedSqlThrowsException(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewrite('CREATE INDEX idx ON users (name)');
    }

    public function testEmptyInputThrowsException(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewrite('');
    }

    public function testMultiStatementThrowsException(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewrite('SELECT 1; SELECT 2');
    }

    public function testRewriteIsDeterministic(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan1 = $rewriter->rewrite('SELECT * FROM users');
        $plan2 = $rewriter->rewrite('SELECT * FROM users');

        self::assertSame($plan1->sql(), $plan2->sql());
        self::assertSame($plan1->kind(), $plan2->kind());
    }

    public function testReadPlanHasNoMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');

        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertNull($plan->mutation());
    }

    public function testWritePlanHasNonNullMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");

        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
        self::assertInstanceOf(InsertMutation::class, $plan->mutation());
    }

    public function testRewriteMultiple(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $multiPlan = $rewriter->rewriteMultiple("SELECT * FROM users; INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");

        self::assertSame(2, $multiPlan->count());
        self::assertSame(QueryKind::READ, $multiPlan->get(0)?->kind());
        self::assertSame(QueryKind::WRITE_SIMULATED, $multiPlan->get(1)?->kind());
    }

    public function testSelectWithShadowDataIncludesCte(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice', 'email' => 'a@b.com']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');

        self::assertStringStartsWith('WITH', $plan->sql());
        self::assertStringContainsString('"users"', $plan->sql());
        self::assertStringContainsString('SELECT', strtoupper($plan->sql()));
    }

    public function testSelectUnknownTableWithSchemaContextThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testSelectKnownTableNoSchemaContextDoesNotThrow(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM whatever');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testSelectWithShadowStoreOnlyHasSchemaContext(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
    }

    public function testUpdateEnsuresShadowStore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
    }

    public function testDeleteFromWithoutWhereReturnsSqlWithSelectWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
    }

    public function testDdlSimulatedReturnsSqlSelectWhere0(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
        self::assertSame(QueryKind::DDL_SIMULATED, $plan->kind());
    }

    public function testRewriteMultipleEmptyThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewriteMultiple('');
    }

    public function testSelectWithRegistryOnlyBuildTableContext(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
        self::assertStringContainsString('WHERE 0', $plan->sql());
    }

    public function testSelectWithShadowDataColumnsInferred(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"users"', $plan->sql());
        self::assertStringContainsString('"id"', $plan->sql());
        self::assertStringContainsString('"name"', $plan->sql());
    }

    public function testDeleteWithWhereProducesTransformedSql(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertStringContainsString('SELECT', $plan->sql());
        self::assertStringContainsString('WHERE id = 1', $plan->sql());
    }

    public function testDeleteEnsuresShadowStore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(DeleteMutation::class, $plan->mutation());
    }

    public function testSelectExistingInShadowStoreIsNotUnknown(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1]]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testInsertWithShadowDataProducesTransformedSql(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("INSERT INTO users (id, name) VALUES (2, 'Bob')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertStringContainsString('SELECT', $plan->sql());
        self::assertNotNull($plan->mutation());
    }

    public function testBuildTableContextMergesColumnsFromMultipleRows(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $store->insert('users', [['id' => 2, 'name' => 'Bob', 'email' => 'b@b.com']]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"id"', $plan->sql());
        self::assertStringContainsString('"name"', $plan->sql());
        self::assertStringContainsString('"email"', $plan->sql());
    }

    public function testBuildTableContextUsesDefinitionColumns(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1]]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"name"', $plan->sql());
    }

    public function testBuildTableContextRegistryOnlyTableIncluded(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $registry->register('orders', new TableDefinition(
            ['id', 'amount'],
            ['id' => 'INTEGER', 'amount' => 'REAL'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"users"', $plan->sql());
    }

    public function testSelectTableInShadowStoreNotUnknown(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $registry = new TableDefinitionRegistry();
        $registry->register('orders', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testDeleteFromQuotedTableWithoutWhereReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('my_table', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('my_table');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM "my_table"');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
    }

    public function testDeleteFromWithSemicolonAndWhitespace(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users ;');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
    }

    public function testUpdateEnsuresShadowStoreCalledOnTarget(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'val'],
            ['id' => 'INTEGER', 'val' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("UPDATE t SET val = 'x' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertSame([], $store->get('t'));
    }

    public function testDeleteEnsuresShadowStoreCalledOnTarget(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'val'],
            ['id' => 'INTEGER', 'val' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM t WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertSame([], $store->get('t'));
    }

    public function testBuildTableContextShadowStoreEmptyRowsNoDefinitionPassesThrough(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT 1');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testHasSchemaContextWithRegistryOnly(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testHasSchemaContextWithShadowStoreOnly(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1]]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testDeleteFromBacktickQuotedTableReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('t');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM `t`');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
    }

    public function testDeleteFromBracketQuotedTableReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('t');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM [t]');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
    }

    public function testUpdateEnsuresShadowStoreForTargetTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
        self::assertSame([], $store->get('users'));
    }

    public function testDeleteEnsuresShadowStoreForTargetTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
    }

    public function testDeleteFromLowercaseReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('delete from users');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
    }

    public function testBuildTableContextIncludesMultipleTablesFromRegistry(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $registry->register('orders', new TableDefinition(
            ['oid', 'uid'],
            ['oid' => 'INTEGER', 'uid' => 'INTEGER'],
            ['oid'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users JOIN orders ON users.id = orders.uid');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testBuildTableContextWithShadowStoreColumnsInferred(): void
    {
        $registry = new TableDefinitionRegistry();
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
        self::assertStringContainsString('"id"', $plan->sql());
        self::assertStringContainsString('"name"', $plan->sql());
    }

    public function testBuildTableContextSkipsAlreadyAddedFromShadowStore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
        self::assertSame(1, substr_count($plan->sql(), '"users" AS'));
    }

    public function testInsertDoesNotEnsureShadowStoreForTargetTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("INSERT INTO users (id, name) VALUES (1, 'Alice')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
    }

    public function testDeleteFromWithCommentsReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('/* comment */ DELETE FROM users');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
    }

    public function testUpdateEnsuresShadowStoreEntry(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertArrayHasKey('users', $store->getAll());
    }

    public function testDeleteEnsuresShadowStoreEntry(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertArrayHasKey('users', $store->getAll());
    }

    public function testSelectWithMultipleRegisteredTablesIncludesAll(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $registry->register('orders', new TableDefinition(
            ['id', 'user_id'],
            ['id' => 'INTEGER', 'user_id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertSame(QueryKind::READ, $plan->kind());
        $sql = $plan->sql();
        self::assertStringContainsString('"users"', $sql);
        self::assertStringContainsString('"orders"', $sql);
    }

    public function testSelectWithShadowStoreAndRegistryTablesMerged(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $store->ensure('orders');
        $store->set('orders', [['id' => 1, 'user_id' => 1]]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);

        $plan = $rewriter->rewrite('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertSame(QueryKind::READ, $plan->kind());
        $sql = $plan->sql();
        self::assertStringContainsString('"users"', $sql);
        self::assertStringContainsString('"orders"', $sql);
    }
}
