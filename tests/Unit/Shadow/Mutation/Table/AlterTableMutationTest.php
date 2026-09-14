<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Table;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\TableAlreadyExistsException;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(AlterTableMutation::class)]
final class AlterTableMutationTest extends TestCase
{
    public function testTableNameResultSelectApplyReplacesDefinitionAndRowsAtSameName(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('people', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $definition = new TableDefinition(
            ['id', 'age'],
            ['id' => 'INTEGER', 'age' => 'INTEGER'],
            ['id'],
            [],
            [],
        );
        $store = new ShadowStore();
        $store->set('people', [['id' => 1]]);
        $mutation = new AlterTableMutation(
            'ALTER TABLE people ADD COLUMN age INTEGER',
            'people',
            'people',
            $definition,
            $registry,
            'SELECT "id", NULL AS "age" FROM "people"',
        );

        $mutation->apply($store, [['id' => 1, 'age' => null]]);

        self::assertSame($definition, $registry->get('people'));
        self::assertFalse($registry->isRemoved('people'));
        self::assertSame([['id' => 1, 'age' => null]], $store->get('people'));
        self::assertSame('people', $mutation->tableName());
        self::assertSame('SELECT "id", NULL AS "age" FROM "people"', $mutation->resultSelect());
    }

    public function testApplyRenameMovesRowsAndRetainsSourceTombstone(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []);
        $registry->register('source', $definition);
        $store = new ShadowStore();
        $store->set('source', [['id' => 1]]);
        $mutation = new AlterTableMutation(
            'ALTER TABLE source RENAME TO target',
            'source',
            'target',
            $definition,
            $registry,
            'SELECT "id" FROM "source"',
        );

        $mutation->apply($store, [['id' => 1]]);

        self::assertTrue($registry->isRemoved('source'));
        self::assertSame($definition, $registry->get('target'));
        self::assertSame([], $store->get('source'));
        self::assertSame([['id' => 1]], $store->get('target'));
    }

    public function testApplyRenameRejectsAnActiveTarget(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []);
        $registry->register('source', $definition);
        $registry->register('target', $definition);
        $mutation = new AlterTableMutation(
            'ALTER TABLE source RENAME TO target',
            'source',
            'target',
            $definition,
            $registry,
            'SELECT "id" FROM "source"',
        );

        $this->expectException(TableAlreadyExistsException::class);
        $mutation->apply(new ShadowStore(), []);
    }

    public function testResultSelectRetainsProjectionWithoutChangingSchema(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id'], [], [], [], []);
        $mutation = new AlterTableMutation('ALTER TABLE users RENAME TO people', 'users', 'people', $definition, $registry, 'SELECT "id" FROM "users"');
        self::assertSame('SELECT "id" FROM "users"', $mutation->resultSelect());
        self::assertFalse($registry->has('people'));
    }

}
