<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Table\Alter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\AlteredTableProjection;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(AlteredTableProjection::class)]
#[UsesClass(AlterTableMutation::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
final class AlteredTableProjectionTest extends TestCase
{
    public function testAlterMutationBuildsProjectionWithoutApplyingIt(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $mutation = (new AlteredTableProjection($registry))->alterMutation('ALTER TABLE users RENAME TO people', 'users', 'people', $definition, ['"id"', '"name"']);
        self::assertSame('SELECT "id", "name" FROM "users"', $mutation->resultSelect());
        self::assertSame('users', $mutation->tableName());
        self::assertFalse($registry->has('people'));
    }

    public function testAlterMutationRejectsRemovingEveryColumn(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $this->expectException(\ZtdQuery\Exception\UnsupportedSqlException::class);
        (new AlteredTableProjection($registry))->alterMutation('ALTER TABLE users DROP COLUMN id', 'users', 'users', $definition, []);
    }

    public function testExistingColumnPreservesCanonicalName(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $projection = new AlteredTableProjection($registry);
        self::assertSame('name', $projection->existingColumn($definition, 'NAME'));
        self::assertNull($projection->existingColumn($definition, 'missing'));
    }

    public function testQuotedColumnsPreservesOrder(): void
    {
        self::assertSame(['"id"', '"full name"'], (new AlteredTableProjection(new TableDefinitionRegistry()))->quotedColumns(['id', 'full name']));
    }

    public function testQuoteEscapesEmbeddedDoubleQuotes(): void
    {
        self::assertSame('"a""b"', (new AlteredTableProjection(new TableDefinitionRegistry()))->quote('a"b'));
    }

}
