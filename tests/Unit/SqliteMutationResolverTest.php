<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\SqliteSchemaParser;
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
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(SqliteMutationResolver::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SqliteSchemaParser::class)]
final class SqliteMutationResolverTest extends TestCase
{
    public function testResolveInsert(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT INTO users (id, name) VALUES (1, 'Alice')", QueryKind::WRITE_SIMULATED);

        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveInsertIgnore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT OR IGNORE INTO users (id, name) VALUES (1, 'Alice')", QueryKind::WRITE_SIMULATED);

        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveReplace(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("REPLACE INTO users (id, name) VALUES (1, 'Alice')", QueryKind::WRITE_SIMULATED);

        self::assertInstanceOf(ReplaceMutation::class, $mutation);
    }

    public function testResolveInsertOrReplace(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT OR REPLACE INTO users (id, name) VALUES (1, 'Alice')", QueryKind::WRITE_SIMULATED);

        self::assertInstanceOf(ReplaceMutation::class, $mutation);
    }

    public function testResolveUpsert(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve(
            "INSERT INTO users (id, name) VALUES (1, 'Alice') ON CONFLICT (id) DO UPDATE SET name = excluded.name",
            QueryKind::WRITE_SIMULATED
        );

        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveUpdate(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $resolver = new SqliteMutationResolver($store, new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE users SET name = 'Bob' WHERE id = 1", QueryKind::WRITE_SIMULATED);

        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveDelete(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM users WHERE id = 1', QueryKind::WRITE_SIMULATED);

        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteUnknownTableThrows(): void
    {
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());

        $this->expectException(UnknownSchemaException::class);
        $resolver->resolve('DELETE FROM unknown_table WHERE id = 1', QueryKind::WRITE_SIMULATED);
    }

    public function testResolveCreateTable(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)', QueryKind::DDL_SIMULATED);

        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveCreateTableAlreadyExistsThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());

        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('CREATE TABLE users (id INTEGER PRIMARY KEY)', QueryKind::DDL_SIMULATED);
    }

    public function testResolveCreateTableIfNotExists(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)', QueryKind::DDL_SIMULATED);

        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDropTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DROP TABLE users', QueryKind::DDL_SIMULATED);

        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveDropTableUnknownThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());

        $this->expectException(UnknownSchemaException::class);
        $resolver->resolve('DROP TABLE unknown_table', QueryKind::DDL_SIMULATED);
    }

    public function testResolveDropTableIfExists(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DROP TABLE IF EXISTS unknown_table', QueryKind::DDL_SIMULATED);

        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users ADD COLUMN phone TEXT', QueryKind::DDL_SIMULATED);

        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users DROP COLUMN email', QueryKind::DDL_SIMULATED);

        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);

        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableUnknownThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());

        $this->expectException(UnknownSchemaException::class);
        $resolver->resolve('ALTER TABLE unknown_table ADD COLUMN x TEXT', QueryKind::DDL_SIMULATED);
    }

    public function testResolveSelectReturnsNull(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('SELECT * FROM users', QueryKind::READ);
        self::assertNull($mutation);
    }

    public function testResolveEmptyReturnsNull(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('', QueryKind::READ);
        self::assertNull($mutation);
    }

    public function testResolveUnsupportedReturnsNull(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('CREATE INDEX idx ON t (col)', QueryKind::READ);
        self::assertNull($mutation);
    }

    public function testResolveUpdateWithoutTargetThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('UPDATE', QueryKind::WRITE_SIMULATED);
    }

    public function testResolveDeleteWithoutTargetThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('DELETE', QueryKind::WRITE_SIMULATED);
    }

    public function testResolveInsertWithoutTargetThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('INSERT', QueryKind::WRITE_SIMULATED);
    }

    public function testResolveCreateTableWithoutTargetThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('CREATE TABLE', QueryKind::DDL_SIMULATED);
    }

    public function testResolveDropTableWithoutTargetThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('DROP TABLE', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterTableRenameTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users RENAME TO people', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveAlterTableUnsupportedOperationThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('ALTER TABLE users SOMETHING WEIRD', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterTableAddColumnWithType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users ADD COLUMN age INTEGER', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnWithoutType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users ADD COLUMN notes', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumnUpdatesSchema(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            ['email_UNIQUE' => ['email']],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users DROP COLUMN email', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameColumnUpdatesSchema(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            ['name_UNIQUE' => ['name']],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableWithoutTargetThrows(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('ALTER TABLE', QueryKind::DDL_SIMULATED);
    }

    public function testResolveDeleteWithExistingRows(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM users WHERE id = 1', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpdateWithPrimaryKeys(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE users SET name = 'Bob' WHERE id = 1", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveOnConflictWithEmptyUpdatesReturnsInsert(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve(
            "INSERT INTO t (id) VALUES (1) ON CONFLICT (id) DO NOTHING",
            QueryKind::WRITE_SIMULATED
        );
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveAlterTableAddWithoutColumnKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users ADD phone TEXT', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameWithoutColumnKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users RENAME name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnUnknownTableThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            [],
            [],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users ADD COLUMN x', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumnUnknownThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolve('ALTER TABLE missing DROP COLUMN x', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterTableRenameColumnUnknownThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnknownSchemaException::class);
        $resolver->resolve('ALTER TABLE missing RENAME COLUMN x TO y', QueryKind::DDL_SIMULATED);
    }

    public function testResolveCreateTableIfNotExistsLowercase(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('create table if not exists t (id integer primary key)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDropTableIfExistsLowercase(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('drop table if exists nonexistent', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table users add column email text', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableDropColumnLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'email'],
            ['id' => 'INTEGER', 'email' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table users drop column email', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameToLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table users rename to people', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveAlterTableRenameColumnLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table users rename column name to full_name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDeleteFullTableWithoutWhere(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM users', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnNoKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD email TEXT', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnWithParenthesizedType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN price DECIMAL(10,2)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnWithPrimaryKeyword(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN note PRIMARY KEY', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableAddColumnVerifyNewColumns(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users ADD COLUMN phone TEXT', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
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
        $registry->register('users', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            ['email_unique' => ['email']],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users DROP COLUMN email', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
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
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            ['name_unique' => ['name']],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE users RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
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
        $registry->register('t', new TableDefinition(
            ['id', 'code'],
            ['id' => 'INTEGER', 'code' => 'TEXT'],
            ['id', 'code'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN code TO code_new', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
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
        $registry->register('t', new TableDefinition(
            ['id', 'email'],
            ['id' => 'INTEGER', 'email' => 'TEXT'],
            ['id'],
            [],
            ['email_uq' => ['email']],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN email TO mail', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['mail'], $def->uniqueConstraints['email_uq']);
    }

    public function testResolveAlterTableRenameColumnTypedColumnsUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'val'],
            ['id' => 'INTEGER', 'val' => 'REAL'],
            ['id'],
            [],
            [],
            ['id' => new \ZtdQuery\Schema\ColumnType(\ZtdQuery\Schema\ColumnTypeFamily::INTEGER, 'INTEGER'), 'val' => new \ZtdQuery\Schema\ColumnType(\ZtdQuery\Schema\ColumnTypeFamily::FLOAT, 'REAL')],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN val TO value', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN email', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['id'], $def->primaryKeys);
    }

    public function testResolveAlterTableDropColumnRemovesPrimaryKeyIfDropped(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'code'],
            ['id' => 'INTEGER', 'code' => 'TEXT'],
            ['id', 'code'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN code', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id', 'email'],
            ['id' => 'INTEGER', 'email' => 'TEXT'],
            ['id'],
            [],
            ['email_uq' => ['email']],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN email', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayNotHasKey('email_uq', $def->uniqueConstraints);
    }

    public function testResolveAlterTableDropColumnPreservesPartialUniqueConstraint(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            [],
            ['name_email_uq' => ['name', 'email']],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN email', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN price DECIMAL(10,2)', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('price', $def->columns);
        self::assertSame('DECIMAL(10,2)', $def->columnTypes['price']);
    }

    public function testResolveInsertTableName(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT INTO orders (id) VALUES (1)", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(InsertMutation::class, $mutation);
        self::assertSame('orders', $mutation->tableName());
    }

    public function testResolveUpdateEnsuresShadowStore(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE t SET x = 1", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpdateMutation::class, $mutation);
        self::assertSame('t', $mutation->tableName());
    }

    public function testResolveDeleteTableName(): void
    {
        $store = new ShadowStore();
        $store->ensure('orders');
        $store->set('orders', [['id' => 1]]);
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("DELETE FROM orders WHERE id = 1", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
        self::assertSame('orders', $mutation->tableName());
    }

    public function testResolveDeleteLowercaseSql(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('delete from users where id = 1', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpsertTableNameAndUpdateColumns(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve(
            "INSERT INTO users (id, name) VALUES (1, 'Alice') ON CONFLICT (id) DO UPDATE SET name = excluded.name",
            QueryKind::WRITE_SIMULATED
        );
        self::assertInstanceOf(UpsertMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveReplaceTableName(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("REPLACE INTO users (id, name) VALUES (1, 'Alice')", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(ReplaceMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveCreateTableTableName(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('CREATE TABLE orders (id INTEGER PRIMARY KEY)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        self::assertSame('orders', $mutation->tableName());
    }

    public function testResolveDropTableTableName(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DROP TABLE users', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveInsertIgnoreWithPrimaryKeys(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT OR IGNORE INTO users (id, name) VALUES (1, 'x')", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(InsertMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveAlterTableRenameToDropsOldTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('old_t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE old_t RENAME TO new_t', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        self::assertSame('old_t', $mutation->tableName());
    }

    public function testResolveAlterTableDropColumnTypedColumnsUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'val'],
            ['id' => 'INTEGER', 'val' => 'TEXT'],
            ['id'],
            [],
            [],
            ['id' => new \ZtdQuery\Schema\ColumnType(\ZtdQuery\Schema\ColumnTypeFamily::INTEGER, 'INTEGER'), 'val' => new \ZtdQuery\Schema\ColumnType(\ZtdQuery\Schema\ColumnTypeFamily::TEXT, 'TEXT')],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN val', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
            ['id' => new \ZtdQuery\Schema\ColumnType(\ZtdQuery\Schema\ColumnTypeFamily::INTEGER, 'INTEGER')],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN val TEXT', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayHasKey('val', $def->typedColumns);
    }

    public function testResolveAlterTableDropColumnNotNullUpdated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name', 'email'],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->notNullColumns);
        self::assertNotContains('name', $def->notNullColumns);
    }

    public function testResolveReturnsNullForUnsupportedClassification(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $result = $resolver->resolve('CREATE INDEX idx ON t (a)', QueryKind::DDL_SIMULATED);
        self::assertNull($result);
    }

    public function testResolveUpdateEnsuresShadowStoreWithDefinition(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE users SET name = 'Bob' WHERE id = 1", QueryKind::WRITE_SIMULATED);
        self::assertNotNull($mutation);
        self::assertInstanceOf(UpdateMutation::class, $mutation);
        self::assertSame([], $store->get('users'));
    }

    public function testResolveUpdatePrimaryKeysFromDefinition(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE users SET name = 'Bob' WHERE id = 1", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveUpdateWithoutDefinitionHasEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'x']]);
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE users SET name = 'Bob' WHERE id = 1", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveDeleteEnsuresShadowStore(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM users WHERE id = 1', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
        self::assertSame([], $store->get('users'));
    }

    public function testResolveDeleteWithoutDefinitionAndNoRowsThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $resolver->resolve('DELETE FROM unknown_table WHERE id = 1', QueryKind::WRITE_SIMULATED);
    }

    public function testResolveUpsertWithDefinitionPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT INTO users (id, name) VALUES (1, 'a') ON CONFLICT (id) DO UPDATE SET name = excluded.name", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveInsertIgnoreWithPrimaryKeysReturnsInsertMutation(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT OR IGNORE INTO users (id, name) VALUES (1, 'a')", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveCreateTableIfNotExistsLowercaseDoesNotThrow(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('CREATE TABLE if not exists t (id INTEGER)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveCreateTableAlreadyExistsNoIfNotExistsThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(\ZtdQuery\Exception\UnsupportedSqlException::class);
        $resolver->resolve('CREATE TABLE t (id INTEGER)', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterTableOnNonExistentTableThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $resolver->resolve('ALTER TABLE nonexistent ADD COLUMN a INTEGER', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterTableUnsupportedOperationThrowsForUnknownAction(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(\ZtdQuery\Exception\UnsupportedSqlException::class);
        $resolver->resolve('ALTER TABLE t WHATEVER', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterTableAddColumnWithoutColumnKeyword(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD name TEXT', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id', 'old_name'],
            ['id' => 'INTEGER', 'old_name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME old_name TO new_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN status TEXT', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN price DECIMAL(10,2)', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('price', $def->columns);
        self::assertSame('DECIMAL(10,2)', $def->columnTypes['price']);
    }

    public function testResolveDeleteWithOnlyTableNameNoWhereNoTrailing(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM users', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDropTableIfExistsOnNonExistentTableDoesNotThrow(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DROP TABLE IF EXISTS nonexistent', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveDropTableNonExistentWithoutIfExistsThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $resolver->resolve('DROP TABLE nonexistent', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterDropColumnRemovesFromColumns(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            ['id', 'name'],
            ['unique_email' => ['email']],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN email', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['id', 'name'], $def->columns);
        self::assertArrayNotHasKey('email', $def->columnTypes);
        self::assertNotContains('email', $def->notNullColumns);
    }

    public function testResolveAlterDropColumnRemovesFromPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id', 'name'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['id'], $def->primaryKeys);
    }

    public function testResolveAlterDropColumnRemovesFromUniqueConstraints(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'email', 'name'],
            ['id' => 'INTEGER', 'email' => 'TEXT', 'name' => 'TEXT'],
            ['id'],
            [],
            ['unique_email' => ['email'], 'unique_name' => ['name']],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN email', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayNotHasKey('unique_email', $def->uniqueConstraints);
        self::assertArrayHasKey('unique_name', $def->uniqueConstraints);
    }

    public function testResolveAlterRenameColumnRenamesInColumns(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'old_col'],
            ['id' => 'INTEGER', 'old_col' => 'TEXT'],
            ['id'],
            ['old_col'],
            ['unique_old' => ['old_col']],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN old_col TO new_col', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('new_col', $def->columns);
        self::assertNotContains('old_col', $def->columns);
        self::assertArrayHasKey('new_col', $def->columnTypes);
        self::assertArrayNotHasKey('old_col', $def->columnTypes);
        self::assertContains('new_col', $def->notNullColumns);
    }

    public function testResolveAlterRenameColumnRenamesInPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['old_pk', 'val'],
            ['old_pk' => 'INTEGER', 'val' => 'TEXT'],
            ['old_pk'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN old_pk TO new_pk', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('new_pk', $def->primaryKeys);
        self::assertNotContains('old_pk', $def->primaryKeys);
    }

    public function testResolveAlterRenameColumnRenamesInUniqueConstraints(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'old_name'],
            ['id' => 'INTEGER', 'old_name' => 'TEXT'],
            ['id'],
            [],
            ['uq_name' => ['old_name']],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN old_name TO new_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('new_name', $def->uniqueConstraints['uq_name']);
        self::assertNotContains('old_name', $def->uniqueConstraints['uq_name']);
    }

    public function testResolveAlterRenameTableProducesDropMutation(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('old_t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE old_t RENAME TO new_t', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveDeleteWithStrippedCommentsMatchesRegex(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM users', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveInsertOnConflictDoNothingIsIgnore(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT INTO users (id, name) VALUES (1, 'a') ON CONFLICT DO NOTHING", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveAlterAddColumnTypeDefaultKeywordExcluded(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("ALTER TABLE t ADD COLUMN active DEFAULT 1", QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('active', $def->columns);
        self::assertArrayNotHasKey('active', $def->columnTypes);
    }

    public function testResolveAlterAddColumnTypeNotKeywordExcluded(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("ALTER TABLE t ADD COLUMN active NOT NULL", QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('active', $def->columns);
        self::assertArrayNotHasKey('active', $def->columnTypes);
    }

    public function testResolveAlterAddColumnWithTextType(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN name TEXT', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame('TEXT', $def->columnTypes['name']);
    }

    public function testResolveAlterAddColumnWithNoRest(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN val', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('val', $def->columns);
        self::assertArrayNotHasKey('val', $def->columnTypes);
    }

    public function testResolveUpdateReturnsMutationWithTargetTable(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE users SET x = 1", QueryKind::WRITE_SIMULATED);
        self::assertNotNull($mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveInsertIsReplaceReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("REPLACE INTO users (id, name) VALUES (1, 'a')", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(ReplaceMutation::class, $mutation);
        self::assertSame('users', $mutation->tableName());
    }

    public function testResolveCreateTableIfNotExistsExistingTableNoError(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('CREATE TABLE IF NOT EXISTS t (id INTEGER)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveDropTableExistingReturnsDropMutation(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DROP TABLE t', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        self::assertSame('t', $mutation->tableName());
    }

    public function testResolveAlterTableWithAddWithoutColumnKeywordDetected(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD email TEXT', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('email', $def->columns);
        self::assertSame('TEXT', $def->columnTypes['email']);
    }

    public function testResolveReturnsNullForUnclassifiableStatement(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        self::assertNull($resolver->resolve('PRAGMA table_info(users)', QueryKind::READ));
    }

    public function testResolveDeleteWithCommentsMatchesRegex(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('/* comment */ DELETE FROM users WHERE id = 1', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteCaseInsensitiveRegex(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('delete from users where id = 1', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveUpsertWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT INTO t (id, name) VALUES (1, 'a') ON CONFLICT (id) DO UPDATE SET name = excluded.name", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveUpsertWithoutDefinitionReturnsEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT INTO t (id, name) VALUES (1, 'a') ON CONFLICT (id) DO UPDATE SET name = excluded.name", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpsertMutation::class, $mutation);
    }

    public function testResolveInsertIgnoreWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT OR IGNORE INTO t (id, name) VALUES (1, 'a')", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveInsertIgnoreWithoutDefinitionReturnsEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT OR IGNORE INTO t (id, name) VALUES (1, 'a')", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveCreateTableCaseInsensitiveIfNotExists(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('create table if not exists t (id integer primary key)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterTableCaseInsensitiveAddColumn(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t add column name text', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t drop column name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t rename column name to full_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->columns);
        self::assertNotContains('name', $def->columns);
    }

    public function testResolveAlterRenameColumnRenamesInTypedColumns(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayHasKey('full_name', $def->columnTypes);
        self::assertArrayNotHasKey('name', $def->columnTypes);
    }

    public function testResolveAlterTableCaseInsensitiveRenameTo(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t rename to t2', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveAlterRenameWithoutColumnKeyword(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->columns);
    }

    public function testResolveAlterAddColumnWithTrimmedRest(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN name  TEXT ', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame('TEXT', $def->columnTypes['name']);
    }

    public function testResolveAlterAddColumnNonTypeKeywordNotStored(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN name PRIMARY KEY', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('name', $def->columns);
        self::assertArrayNotHasKey('name', $def->columnTypes);
    }

    public function testResolveAlterAddColumnWithParensInType(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN amount DECIMAL(10,2)', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame('DECIMAL(10,2)', $def->columnTypes['amount']);
    }

    public function testResolveAlterDropColumnRemovesTypedColumns(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['id'],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayNotHasKey('name', $def->columnTypes);
    }

    public function testResolveAlterDropColumnPreservesRemainingUniqueConstraints(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            [],
            ['idx_name' => ['name'], 'idx_email' => ['email']],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayHasKey('idx_email', $def->uniqueConstraints);
        self::assertArrayNotHasKey('idx_name', $def->uniqueConstraints);
    }

    public function testResolveAlterDropColumnFiltersMultiColumnConstraint(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'email'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
            ['id'],
            [],
            ['idx_combo' => ['name', 'email']],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertArrayHasKey('idx_combo', $def->uniqueConstraints);
        self::assertSame(['email'], $def->uniqueConstraints['idx_combo']);
    }

    public function testResolveAlterRenameColumnUpdatesUniqueConstraints(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            ['idx_name' => ['name']],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['full_name'], $def->uniqueConstraints['idx_name']);
    }

    public function testResolveAlterRenameColumnUpdatesPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id', 'name'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->primaryKeys);
        self::assertNotContains('name', $def->primaryKeys);
    }

    public function testResolveAlterRenameColumnUpdatesNotNullColumns(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            ['name'],
            [],
        ));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('full_name', $def->notNullColumns);
        self::assertNotContains('name', $def->notNullColumns);
    }

    public function testResolveDropTableCaseInsensitiveIfExists(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('drop table if exists nonexistent', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveUpdateWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE t SET name = 'Bob' WHERE id = 1", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveUpdateWithoutDefinitionReturnsEmptyPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $store->ensure('t');
        $store->set('t', [['id' => 1, 'name' => 'Alice']]);
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("UPDATE t SET name = 'Bob' WHERE id = 1", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(UpdateMutation::class, $mutation);
    }

    public function testResolveDeleteWithDefinitionReturnsPrimaryKeys(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM t WHERE id = 1', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveDeleteWithoutDefinitionButWithShadowRows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $store->ensure('t');
        $store->set('t', [['id' => 1]]);
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('DELETE FROM t WHERE id = 1', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveOnConflictDoNothingIsNotUpsert(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("INSERT INTO t (id) VALUES (1) ON CONFLICT DO NOTHING", QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(InsertMutation::class, $mutation);
    }

    public function testResolveAlterAddColumnWithEmptyRestNoType(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('name', $def->columns);
        self::assertArrayNotHasKey('name', $def->columnTypes);
    }

    public function testResolveAlterAddColumnUpperCasesTypeName(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN name text', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame('TEXT', $def->columnTypes['name']);
    }

    public function testResolveAlterAddWithoutColumnKeywordLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t add email text', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveCreateTableIfNotExistsExistingTableLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('create table if not exists t (id integer primary key)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterRenameColumnLowercaseRegex(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t rename column name to fullname', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $registry->unregister('t');
        $store = new ShadowStore();
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('fullname', $def->columns);
        self::assertNotContains('name', $def->columns);
    }

    public function testResolveAlterRenameWithoutColumnKeywordLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t rename name to fullname', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveAlterDropColumnLowercaseRegex(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t drop column name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $registry->unregister('t');
        $store = new ShadowStore();
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertNotContains('name', $def->columns);
    }

    public function testResolveAlterAddColumnLowercaseTypeExtraction(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t add column age integer', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('age', $def->columns);
        self::assertSame('INTEGER', $def->columnTypes['age']);
    }

    public function testResolveAlterRenameToLowercaseRegex(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id'],
            ['id' => 'INTEGER'],
            ['id'],
            [],
            [],
        ));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t rename to t2', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveDropTableIfExistsLowercaseRegex(): void
    {
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('drop table if exists nonexistent', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
    }

    public function testResolveDeleteFullTableLowercaseMatchesRegex(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('delete from t', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveAlterAddQuotedColumnLowercaseAdd(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t add "email" text', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
    }

    public function testResolveCreateTableIfNotExistsWithoutExistingTable(): void
    {
        $resolver = new SqliteMutationResolver(new ShadowStore(), new TableDefinitionRegistry(), new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        self::assertSame('t', $mutation->tableName());
    }

    public function testResolveCreateTableWithoutIfNotExistsAlreadyExistsThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver(new ShadowStore(), $registry, new SqliteSchemaParser(), new SqliteParser());
        $this->expectException(UnsupportedSqlException::class);
        $resolver->resolve('CREATE TABLE t (id INTEGER PRIMARY KEY)', QueryKind::DDL_SIMULATED);
    }

    public function testResolveAlterAddColumnMultiWordTypeLowercase(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('alter table t add column descr varying character(100)', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('descr', $def->columns);
        self::assertSame('VARYING CHARACTER(100)', $def->columnTypes['descr']);
    }

    public function testResolveAlterAddColumnIfNotExistsDoesNotThrow(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN email TEXT', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertTrue($registry->has('t'));
    }

    public function testResolveAlterDropColumnIfNotExistsDoesNotThrow(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertTrue($registry->has('t'));
    }

    public function testResolveAlterRenameTableIfExistsDoesNotThrow(): void
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
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME TO t2', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertFalse($registry->has('t'));
    }

    public function testResolveAlterRenameColumnIfNotExistsDoesNotThrow(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name'],
            ['id' => 'INTEGER', 'name' => 'TEXT'],
            ['id'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN name TO fullname', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertTrue($registry->has('t'));
    }

    public function testResolveAlterAddColumnMultilineType(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve("ALTER TABLE t ADD COLUMN email\nTEXT", QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertContains('email', $def->columns);
        self::assertSame('TEXT', $def->columnTypes['email']);
    }

    public function testResolveAlterDropColumnFiltersByColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'age'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'age' => 'INTEGER'],
            ['id'],
            ['id', 'name'],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertNotNull($mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame(['id', 'age'], $def->columns);
        self::assertSame(['id'], $def->notNullColumns);
    }

    public function testResolveUpdateEnsuresShadowStoreEntryCreated(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        self::assertSame([], $store->getAll());
        $resolver->resolve("UPDATE t SET x = 1", QueryKind::WRITE_SIMULATED);
        self::assertArrayHasKey('t', $store->getAll());
    }

    public function testResolveDeleteEnsuresShadowStoreEntryCreated(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        self::assertSame([], $store->getAll());
        $resolver->resolve('DELETE FROM t WHERE id = 1', QueryKind::WRITE_SIMULATED);
        self::assertArrayHasKey('t', $store->getAll());
    }

    public function testResolveDeleteWithLeadingWhitespaceLowercase(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store->ensure('users');
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('  delete from users where id = 1  ', QueryKind::WRITE_SIMULATED);
        self::assertInstanceOf(DeleteMutation::class, $mutation);
    }

    public function testResolveAlterAddColumnDoesNotThrowWhenApplied(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN val TEXT', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertTrue($registry->has('t'));
    }

    public function testResolveAlterDropColumnDoesNotThrowWhenApplied(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'val'], ['id' => 'INTEGER', 'val' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN val', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertTrue($registry->has('t'));
    }

    public function testResolveAlterRenameTableAppliesAsDrop(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('old_t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE old_t RENAME TO new_t', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertFalse($registry->has('old_t'));
    }

    public function testResolveAlterRenameColumnDoesNotThrowWhenApplied(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME COLUMN name TO full_name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $mutation->apply($store, []);
        self::assertTrue($registry->has('t'));
    }

    public function testResolveAlterRenameToExtractsCorrectNewName(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME TO new_table_name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        self::assertSame('t', $mutation->tableName());
    }

    public function testResolveAlterAddColumnEmptyParensDoesNotAppendParens(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t ADD COLUMN val INT()', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame('INT', $def->columnTypes['val']);
    }

    public function testResolveAlterDropColumnReindexesNotNull(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['id', 'name', 'age'],
            ['id' => 'INTEGER', 'name' => 'TEXT', 'age' => 'INTEGER'],
            ['id'],
            ['id', 'name', 'age'],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN name', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame([0, 1], array_keys($def->notNullColumns));
        self::assertSame([0], array_keys($def->primaryKeys));
    }

    public function testResolveAlterRenameToDoesNotThrowWhenTableAlreadyGone(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t RENAME TO new_t', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(DropTableMutation::class, $mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        self::assertFalse($registry->has('t'));
    }

    public function testResolveAlterDropColumnReindexesPrimaryKeys(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(
            ['a', 'b', 'c'],
            ['a' => 'INTEGER', 'b' => 'INTEGER', 'c' => 'INTEGER'],
            ['a', 'b', 'c'],
            [],
            [],
        ));
        $store = new ShadowStore();
        $resolver = new SqliteMutationResolver($store, $registry, new SqliteSchemaParser(), new SqliteParser());
        $mutation = $resolver->resolve('ALTER TABLE t DROP COLUMN b', QueryKind::DDL_SIMULATED);
        self::assertInstanceOf(CreateTableMutation::class, $mutation);
        $registry->unregister('t');
        $mutation->apply($store, []);
        $def = $registry->get('t');
        self::assertNotNull($def);
        self::assertSame([0, 1], array_keys($def->primaryKeys));
        self::assertSame(['a', 'c'], $def->primaryKeys);
    }
}
