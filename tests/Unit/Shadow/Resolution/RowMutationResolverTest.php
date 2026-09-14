<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Shadow\Resolution\RowMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\Row\DeleteMutation;
use ZtdQuery\Shadow\Mutation\Row\UpdateMutation;
use ZtdQuery\Shadow\ShadowStore;
use ZtdQuery\Shadow\ShadowTableState;

#[CoversClass(RowMutationResolver::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class RowMutationResolverTest extends TestCase
{
    public function testResolveUpdate(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $resolver = new RowMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), $store);
        $mutation = $resolver->resolveUpdate("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveDelete(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM users WHERE id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteUnknownTableThrows(): void
    {
        $store = new ShadowStore();
        $resolver = new RowMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), $store);
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveDelete('DELETE FROM unknown_table WHERE id = 1');
    }

    public function testResolveUpdateWithoutTargetThrows(): void
    {
        $resolver = new RowMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new ShadowStore());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveUpdate('UPDATE');
    }

    public function testResolveDeleteWithoutTargetThrows(): void
    {
        $resolver = new RowMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new ShadowStore());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveDelete('DELETE');
    }

    public function testResolveDeleteWithExistingRows(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $registry = new TableDefinitionRegistry();
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM users WHERE id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpdateWithPrimaryKeys(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveUpdate("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveDeleteFullTableWithoutWhere(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM users');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpdateWithoutContextThrowsUnknownSchema(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveUpdate('UPDATE t SET x = 1');
    }

    public function testResolveDeleteTableName(): void
    {
        $store = new ShadowStore();
        $store->ensure('orders');
        $store->set('orders', [['id' => 1]]);
        $registry = new TableDefinitionRegistry();
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM orders WHERE id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
        self::assertSame('orders', $mutation->tableName());
    }

    public function testResolveDeleteLowercaseSql(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('delete from users where id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpdateEnsuresShadowStoreWithDefinition(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveUpdate("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertInstanceOf(UpdateMutation::class, $mutation);
        self::assertSame(ShadowTableState::Initialized, $store->state('users'));
    }

    public function testResolveUpdatePrimaryKeysFromDefinition(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveUpdate("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveUpdateWithoutDefinitionHasEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'x']]);
        $registry = new TableDefinitionRegistry();
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveUpdate("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveDeleteEnsuresShadowStore(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM users WHERE id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
        self::assertSame([], $store->get('users'));
    }

    public function testResolveDeleteWithoutDefinitionAndNoRowsThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveDelete('DELETE FROM unknown_table WHERE id = 1');
    }

    public function testResolveDeleteWithOnlyTableNameNoWhereNoTrailing(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM users');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteWithStrippedCommentsMatchesRegex(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM users');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpdateWithoutTargetContextThrowsUnknownSchema(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveUpdate('UPDATE users SET x = 1');
    }

    public function testResolveDeleteWithCommentsMatchesRegex(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('/* comment */ DELETE FROM users WHERE id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteCaseInsensitiveRegex(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('delete from users where id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpdateWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveUpdate("UPDATE t SET name = 'Bob' WHERE id = 1");
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveUpdateWithoutDefinitionReturnsEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $store->ensure('t');
        $store->set('t', [['id' => 1, 'name' => 'Alice']]);
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveUpdate("UPDATE t SET name = 'Bob' WHERE id = 1");
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveDeleteWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM t WHERE id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteWithoutDefinitionButWithShadowRows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $store->ensure('t');
        $store->set('t', [['id' => 1]]);
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('DELETE FROM t WHERE id = 1');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteFullTableLowercaseMatchesRegex(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('t');
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('delete from t');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteEnsuresShadowStoreEntryCreated(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        self::assertSame([], $store->getAll());
        $resolver->resolveDelete('DELETE FROM t WHERE id = 1');
        self::assertArrayHasKey('t', $store->getAll());
    }

    public function testResolveDeleteWithLeadingWhitespaceLowercase(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store->ensure('users');
        $resolver = new RowMutationResolver(new SqliteParser(), $registry, $store);
        $mutation = $resolver->resolveDelete('  delete from users where id = 1  ');
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

}
