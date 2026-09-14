<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Shadow\Resolution\InsertMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\Row\InsertMutation;
use ZtdQuery\Shadow\Mutation\Row\ReplaceMutation;
use ZtdQuery\Shadow\Mutation\UpsertMutation;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(InsertMutationResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ArithmeticExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ComparisonExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionCursor::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionTokenDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\LogicalExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\PrimaryExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\SqliteUpsertExpressionParser::class)]
final class InsertMutationResolverTest extends TestCase
{
    public function testResolveInsertInsert(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT INTO users (id, name) VALUES (1, 'Alice')");
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveInsertInsertIgnore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT OR IGNORE INTO users (id, name) VALUES (1, 'Alice')");
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveInsertReplace(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("REPLACE INTO users (id, name) VALUES (1, 'Alice')");
        self::assertInstanceOf(ReplaceMutation::class, $mutation);
    }

    public function testResolveInsertInsertOrReplace(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT OR REPLACE INTO users (id, name) VALUES (1, 'Alice')");
        self::assertInstanceOf(ReplaceMutation::class, $mutation);
    }

    public function testResolveInsertUpsert(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT INTO users (id, name) VALUES (1, 'Alice') ON CONFLICT (id) DO UPDATE SET name = upper(users.name)");
        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveInsertInsertWithoutTargetThrows(): void
    {
        $resolver = new InsertMutationResolver(new SqliteParser(), new TableDefinitionRegistry());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveInsert('INSERT');
    }

    public function testResolveInsertInsertTableName(): void
    {
        $resolver = new InsertMutationResolver(new SqliteParser(), new TableDefinitionRegistry());
        $mutation = $resolver->resolveInsert('INSERT INTO orders (id) VALUES (1)');
        self::assertInstanceOf(InsertMutation::class, $mutation);
        self::assertSame('orders', $mutation->tableName());
    }

    public function testResolveInsertUpsertTableNameAndUpdateColumns(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT INTO users (id, name) VALUES (1, 'Alice') ON CONFLICT (id) DO UPDATE SET name = excluded.name");
        self::assertInstanceOf(UpsertMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveInsertReplaceTableName(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("REPLACE INTO users (id, name) VALUES (1, 'Alice')");
        self::assertInstanceOf(ReplaceMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveInsertInsertIgnoreWithPrimaryKeys(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT OR IGNORE INTO users (id, name) VALUES (1, 'x')");
        self::assertInstanceOf(InsertMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveInsertUpsertWithDefinitionPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT INTO users (id, name) VALUES (1, 'a') ON CONFLICT (id) DO UPDATE SET name = excluded.name");
        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveInsertInsertIgnoreWithPrimaryKeysReturnsInsertMutation(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT OR IGNORE INTO users (id, name) VALUES (1, 'a')");
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveInsertInsertOnConflictDoNothingIsIgnore(): void
    {
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'existing']]);
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT INTO users (id, name) VALUES (1, 'a') ON CONFLICT DO NOTHING");
        self::assertInstanceOf(InsertMutation::class, $mutation);
        $mutation->apply($store, [['id' => 1, 'name' => 'ignored']]);
        self::assertSame([['id' => 1, 'name' => 'existing']], $store->get('users'));
    }

    public function testResolveInsertInsertIsReplaceReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("REPLACE INTO users (id, name) VALUES (1, 'a')");
        self::assertInstanceOf(ReplaceMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveInsertUpsertWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT INTO t (id, name) VALUES (1, 'a') ON CONFLICT (id) DO UPDATE SET name = excluded.name");
        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveInsertUpsertWithoutDefinitionReturnsEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT INTO t (id, name) VALUES (1, 'a') ON CONFLICT (id) DO UPDATE SET name = excluded.name");
        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveInsertInsertIgnoreWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT OR IGNORE INTO t (id, name) VALUES (1, 'a')");
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveInsertInsertIgnoreWithoutDefinitionReturnsEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new InsertMutationResolver(new SqliteParser(), $registry);
        $mutation = $resolver->resolveInsert("INSERT OR IGNORE INTO t (id, name) VALUES (1, 'a')");
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveOnConflictConsumesNativeEvaluationMetadata(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $mutation = (new InsertMutationResolver(new SqliteParser(), $registry))->resolveOnConflict("INSERT INTO users(id, name) VALUES (1, 'bob') ON CONFLICT(id) DO UPDATE SET name = upper(EXCLUDED.name)", 'users');
        $mutation->apply($store, [['id' => 1, 'name' => 'bob', '__ztd_upsert_value_0' => 'BOB']]);
        self::assertSame([['id' => 1, 'name' => 'BOB']], $store->get('users'));
    }

    public function testResolveOnConflictDoNothingPreservesExistingRow(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], [], ['id'], [], []));
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $mutation = (new InsertMutationResolver(new SqliteParser(), $registry))->resolveOnConflict("INSERT INTO users(id, name) VALUES (1, 'Bob') ON CONFLICT(id) DO NOTHING", 'users');
        $mutation->apply($store, [['id' => 1, 'name' => 'Bob']]);
        self::assertSame([['id' => 1, 'name' => 'Alice']], $store->get('users'));
    }

}
