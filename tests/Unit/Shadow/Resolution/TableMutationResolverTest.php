<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation;
use ZtdQuery\Platform\Sqlite\Shadow\Resolution\TableMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\Table\CreateTableMutation;
use ZtdQuery\Shadow\Mutation\Table\DropTableMutation;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(TableMutationResolver::class)]
#[UsesClass(AlterTableMutation::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\AddColumnResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\AlteredTableProjection::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\ColumnDefinitionEditor::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\DropColumnResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\RenameResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\ColumnDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\TableBodyParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\TableDefinitionBuilder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\VirtualTableParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyEntryParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyTokens::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\SqliteColumnTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\SqliteForeignKeyDefinitionParser::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SqliteSchemaParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class TableMutationResolverTest extends TestCase
{
    public function testResolveCreateTable(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveCreateTableAlreadyExistsThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveCreateTable('CREATE TABLE users (id INTEGER PRIMARY KEY)');
    }

    public function testResolveCreateTableIfNotExists(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDropTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('DROP TABLE users');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveDropTableUnknownThrows(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveDropTable('DROP TABLE unknown_table');
    }

    public function testResolveDropTableIfExists(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('DROP TABLE IF EXISTS unknown_table');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users ADD COLUMN phone TEXT');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users DROP COLUMN email');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users RENAME COLUMN name TO full_name');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableUnknownThrows(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveAlterTable('ALTER TABLE unknown_table ADD COLUMN x TEXT');
    }

    public function testResolveCreateTableWithoutTargetThrows(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveCreateTable('CREATE TABLE');
    }

    public function testResolveDropTableWithoutTargetThrows(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveDropTable('DROP TABLE');
    }

    public function testResolveAlterTableRenameTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users RENAME TO people');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableUnsupportedOperationThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveAlterTable('ALTER TABLE users SOMETHING WEIRD');
    }

    public function testResolveAlterTableAddColumnWithType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users ADD COLUMN age INTEGER');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnWithoutType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users ADD COLUMN notes');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumnUpdatesSchema(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], ['email_UNIQUE' => ['email']]));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users DROP COLUMN email');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameColumnUpdatesSchema(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id', 'name'], ['name_UNIQUE' => ['name']]));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users RENAME COLUMN name TO full_name');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableWithoutTargetThrows(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveAlterTable('ALTER TABLE');
    }

    public function testResolveAlterTableAddWithoutColumnKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users ADD phone TEXT');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameWithoutColumnKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users RENAME name TO full_name');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnUnknownTableThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], [], [], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users ADD COLUMN x');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumnUnknownThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveAlterTable('ALTER TABLE missing DROP COLUMN x');
    }

    public function testResolveAlterTableRenameColumnUnknownThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveAlterTable('ALTER TABLE missing RENAME COLUMN x TO y');
    }

    public function testResolveCreateTableIfNotExistsLowercase(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('create table if not exists t (id integer primary key)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDropTableIfExistsLowercase(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('drop table if exists nonexistent');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table users add column email text');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumnLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'email'], ['id' => 'INTEGER', 'email' => 'TEXT'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table users drop column email');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameToLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table users rename to people');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameColumnLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table users rename column name to full_name');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnNoKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD email TEXT');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnWithParenthesizedType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD COLUMN price DECIMAL(10,2)');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnWithPrimaryKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD COLUMN note PRIMARY KEY');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnVerifyNewColumns(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users ADD COLUMN phone TEXT');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
        $registry->unregister('users');
        $mutation->apply($store, []);
        $def = $registry->get('users');
        self::assertNotNull($def);
        self::assertContains('phone', $def->columns);
        self::assertContains('id', $def->columns);
        self::assertContains('name', $def->columns);
        self::assertSame('TEXT', $def->columnTypes['phone']);
    }

    public function testResolveAlterTableDropColumnVerifyRemovedFromAllLists(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], ['email_unique' => ['email']]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users DROP COLUMN email');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
        $registry->unregister('users');
        $mutation->apply($store, []);
        $def = $registry->get('users');
        self::assertNotNull($def);
        self::assertNotContains('email', $def->columns);
        self::assertContains('id', $def->columns);
        self::assertContains('name', $def->columns);
        self::assertArrayNotHasKey('email', $def->columnTypes);
        self::assertNotContains('email', $def->notNullColumns);
    }

    public function testResolveAlterTableRenameColumnVerifyAllUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id', 'name'], ['name_unique' => ['name']]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE users RENAME COLUMN name TO full_name');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
        $registry->unregister('users');
        $mutation->apply($store, []);
        $def = $registry->get('users');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->columns);
        self::assertNotContains('name', $def->columns);
        self::assertArrayHasKey('full_name', $def->columnTypes);
        self::assertArrayNotHasKey('name', $def->columnTypes);
        self::assertSame('TEXT', $def->columnTypes['full_name']);
        self::assertContains('full_name', $def->notNullColumns);
        self::assertNotContains('name', $def->notNullColumns);
    }

    public function testResolveAlterTableRenameColumnPrimaryKeysUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'code'], ['id' => 'INTEGER', 'code' => 'TEXT'], ['id', 'code'], [], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t RENAME COLUMN code TO code_new');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('code_new', $def->primaryKeys);
        self::assertNotContains('code', $def->primaryKeys);
    }

    public function testResolveAlterTableRenameColumnUniqueConstraintsUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'email'], ['id' => 'INTEGER', 'email' => 'TEXT'], ['id'], [], ['email_uq' => ['email']]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t RENAME COLUMN email TO mail');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['mail'], $def->uniqueConstraints['email_uq']);
    }

    public function testResolveAlterTableRenameColumnTypedColumnsUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'val'], ['id' => 'INTEGER', 'val' => 'REAL'], ['id'], [], [], ['id' => new ColumnDeclaration(ColumnTypeFamily::INTEGER, 'INTEGER'), 'val' => new ColumnDeclaration(ColumnTypeFamily::FLOAT, 'REAL')]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t RENAME COLUMN val TO value');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayHasKey('value', $def->typedColumns);
        self::assertArrayNotHasKey('val', $def->typedColumns);
    }

    public function testResolveAlterTableDropColumnPreservesPrimaryKeys(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id'], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t DROP COLUMN email');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['id'], $def->primaryKeys);
    }

    public function testResolveAlterTableDropColumnRemovesPrimaryKeyIfDropped(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'code'], ['id' => 'INTEGER', 'code' => 'TEXT'], ['id', 'code'], [], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t DROP COLUMN code');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['id'], $def->primaryKeys);
        self::assertNotContains('code', $def->primaryKeys);
    }

    public function testResolveAlterTableDropColumnRemovesUniqueConstraintIfEmpty(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'email'], ['id' => 'INTEGER', 'email' => 'TEXT'], ['id'], [], ['email_uq' => ['email']]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t DROP COLUMN email');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayNotHasKey('email_uq', $def->uniqueConstraints);
    }

    public function testResolveAlterTableDropColumnPreservesPartialUniqueConstraint(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], [], ['name_email_uq' => ['name', 'email']]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t DROP COLUMN email');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayHasKey('name_email_uq', $def->uniqueConstraints);
        self::assertSame(['name'], $def->uniqueConstraints['name_email_uq']);
    }

    public function testResolveAlterTableAddColumnVerifyParenthesizedType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD COLUMN price DECIMAL(10,2)');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('price', $def->columns);
        self::assertSame('DECIMAL(10,2)', $def->columnTypes['price']);
    }

    public function testResolveCreateTableTableName(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        self::assertSame('orders', $mutation->tableName());
    }

    public function testResolveDropTableTableName(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('DROP TABLE users');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveAlterTableRenameToDropsOldTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('old_t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE old_t RENAME TO new_t');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
        self::assertSame('old_t', $mutation->tableName());
    }

    public function testResolveAlterTableDropColumnTypedColumnsUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'val'], ['id' => 'INTEGER', 'val' => 'TEXT'], ['id'], [], [], ['id' => new ColumnDeclaration(ColumnTypeFamily::INTEGER, 'INTEGER'), 'val' => new ColumnDeclaration(ColumnTypeFamily::TEXT, 'TEXT')]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t DROP COLUMN val');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayNotHasKey('val', $def->typedColumns);
        self::assertArrayHasKey('id', $def->typedColumns);
    }

    public function testResolveAlterTableAddColumnWithTypedColumns(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], [], ['id' => new ColumnDeclaration(ColumnTypeFamily::INTEGER, 'INTEGER')]));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD COLUMN val TEXT');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayHasKey('val', $def->typedColumns);
    }

    public function testResolveAlterTableDropColumnNotNullUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name', 'email'], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t DROP COLUMN name');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertNotContains('name', $def->notNullColumns);
        self::assertContains('id', $def->notNullColumns);
        self::assertContains('email', $def->notNullColumns);
    }

    public function testResolveAlterTableRenameColumnNotNullUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t RENAME COLUMN name TO full_name');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->notNullColumns);
        self::assertNotContains('name', $def->notNullColumns);
    }

    public function testResolveCreateTableIfNotExistsLowercaseDoesNotThrow(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('CREATE TABLE if not exists t (id INTEGER)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveCreateTableAlreadyExistsNoIfNotExistsThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveCreateTable('CREATE TABLE t (id INTEGER)');
    }

    public function testResolveAlterTableOnNonExistentTableThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveAlterTable('ALTER TABLE nonexistent ADD COLUMN a INTEGER');
    }

    public function testResolveAlterTableUnsupportedOperationThrowsForUnknownAction(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveAlterTable('ALTER TABLE t WHATEVER');
    }

    public function testResolveAlterTableAddColumnWithoutColumnKeyword(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD name TEXT');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('name', $def->columns);
    }

    public function testResolveAlterTableRenameColumnWithoutColumnKeyword(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'old_name'], ['id' => 'INTEGER', 'old_name' => 'TEXT'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t RENAME old_name TO new_name');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('new_name', $def->columns);
        self::assertNotContains('old_name', $def->columns);
    }

    public function testResolveAlterTableAddColumnAppliesNewColumnToDefinition(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD COLUMN status TEXT');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('status', $def->columns);
    }

    public function testResolveAlterTableAddColumnAppliesParenthesizedType(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD COLUMN price DECIMAL(10,2)');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('price', $def->columns);
        self::assertSame('DECIMAL(10,2)', $def->columnTypes['price']);
    }

    public function testResolveDropTableIfExistsOnNonExistentTableDoesNotThrow(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('DROP TABLE IF EXISTS nonexistent');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveDropTableNonExistentWithoutIfExistsThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolveDropTable('DROP TABLE nonexistent');
    }

    public function testResolveCreateTableIfNotExistsExistingTableNoError(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('CREATE TABLE IF NOT EXISTS t (id INTEGER)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDropTableExistingReturnsDropMutation(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('DROP TABLE t');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        self::assertSame('t', $mutation->tableName());
    }

    public function testResolveAlterTableWithAddWithoutColumnKeywordDetected(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('ALTER TABLE t ADD email TEXT');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('email', $def->columns);
        self::assertSame('TEXT', $def->columnTypes['email']);
    }

    public function testResolveCreateTableCaseInsensitiveIfNotExists(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('create table if not exists t (id integer primary key)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableCaseInsensitiveAddColumn(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table t add column name text');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('name', $def->columns);
    }

    public function testResolveAlterTableCaseInsensitiveDropColumn(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], ['idx_name' => ['name']]));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table t drop column name');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertNotContains('name', $def->columns);
    }

    public function testResolveAlterTableCaseInsensitiveRenameColumn(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['name'], ['idx_name' => ['name']]));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table t rename column name to full_name');
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->columns);
        self::assertNotContains('name', $def->columns);
    }

    public function testResolveAlterTableCaseInsensitiveRenameTo(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveAlterTable('alter table t rename to t2');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
    }

    public function testResolveDropTableCaseInsensitiveIfExists(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('drop table if exists nonexistent');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveCreateTableIfNotExistsExistingTableLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('create table if not exists t (id integer primary key)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDropTableIfExistsLowercaseRegex(): void
    {
        $registry = new TableDefinitionRegistry();
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $mutation = $resolver->resolveDropTable('drop table if exists nonexistent');
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveCreateTableIfNotExistsWithoutExistingTable(): void
    {
        $resolver = new TableMutationResolver(new SqliteParser(), new TableDefinitionRegistry(), new SqliteSchemaParser());
        $mutation = $resolver->resolveCreateTable('CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)');
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        self::assertSame('t', $mutation->tableName());
    }

    public function testResolveCreateTableWithoutIfNotExistsAlreadyExistsThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new TableMutationResolver(new SqliteParser(), $registry, new SqliteSchemaParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolveCreateTable('CREATE TABLE t (id INTEGER PRIMARY KEY)');
    }

}
