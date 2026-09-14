<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Table\Alter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\RenameResolver;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation;
use ZtdQuery\Schema\Key\ForeignKeyDefinition;
use ZtdQuery\Schema\Key\ReferentialAction;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(RenameResolver::class)]
#[UsesClass(AlterTableMutation::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\AlteredTableProjection::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\ColumnDefinitionEditor::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class RenameResolverTest extends TestCase
{
    public function testResolveAlterRenameTableMovesSchemaAndRowsAtApply(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $mutation = (new RenameResolver($registry))->resolveAlterRenameTable('ALTER TABLE users RENAME TO people', 'users', 'people');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
        self::assertSame('SELECT "id", "name" FROM "users"', $mutation->resultSelect());
        $mutation->apply($store, [['id' => 1, 'name' => 'Alice']]);
        self::assertFalse($registry->has('users'));
        self::assertTrue($registry->has('people'));
        self::assertFalse($store->has('users'));
        self::assertSame([['id' => 1, 'name' => 'Alice']], $store->get('people'));
    }

    public function testResolveAlterRenameColumnProjectsNewName(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $mutation = (new RenameResolver($registry))->resolveAlterRenameColumn('ALTER TABLE users RENAME COLUMN NAME TO label', 'users', 'NAME TO label');
        self::assertInstanceOf(AlterTableMutation::class, $mutation);
        self::assertSame('SELECT "id", "name" AS "label" FROM "users"', $mutation->resultSelect());
        $mutation->apply($store, [['id' => 1, 'label' => 'Alice']]);
        self::assertSame(['id', 'label'], $registry->get('users')?->columns);
    }

    public function testResolveAlterRenameColumnRejectsExistingTargetName(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $this->expectException(\ZtdQuery\Exception\ColumnAlreadyExistsException::class);
        (new RenameResolver($registry))->resolveAlterRenameColumn('ALTER TABLE users RENAME COLUMN name TO id', 'users', 'name TO id');
    }

    public function testDefinitionWithRenamedColumnUpdatesKeyAndValueMaps(): void
    {
        $definition = new TableDefinition(
            ['parent', 'label'],
            ['parent' => 'INTEGER', 'label' => 'TEXT'],
            ['parent'],
            ['parent'],
            ['unique_parent' => ['parent']],
            ['parent' => new \ZtdQuery\Schema\ColumnDeclaration(\ZtdQuery\Schema\ColumnTypeFamily::INTEGER, 'INTEGER')],
            ['parent' => '0'],
            ['parent' => \ZtdQuery\Schema\Key\IdentityGenerationStrategy::MaxValue],
            ['parent' => '(1)'],
            ['fk' => new ForeignKeyDefinition(['parent'], 'other', ['id'], ReferentialAction::Cascade, ReferentialAction::SetNull)],
        );
        $result = (new RenameResolver(new TableDefinitionRegistry()))->definitionWithRenamedColumn($definition, 'parent', 'parent_id');
        self::assertSame(['parent_id', 'label'], $result->columns);
        self::assertSame(['parent_id'], $result->primaryKeys);
        self::assertSame(['parent_id'], $result->notNullColumns);
        self::assertSame(['unique_parent' => ['parent_id']], $result->uniqueConstraints);
        self::assertSame(['parent_id' => '0'], $result->columnDefaults);
        self::assertSame(['parent_id' => '(1)'], $result->generatedExpressions);
        self::assertSame(['parent_id'], array_keys($result->typedColumns));
        self::assertSame(['parent_id'], array_keys($result->identityStrategies));
        self::assertSame(['parent_id'], $result->foreignKeys['fk']->columns);
        self::assertSame(['id'], $result->foreignKeys['fk']->referencedColumns);
        self::assertSame(ReferentialAction::Cascade, $result->foreignKeys['fk']->onDelete);
    }

}
