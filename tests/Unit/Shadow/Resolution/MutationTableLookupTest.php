<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(MutationTableLookup::class)]
final class MutationTableLookupTest extends TestCase
{
    public function testDefinitionReturnsTheRegisteredSchema(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        self::assertSame($definition, (new MutationTableLookup($registry))->definition('UPDATE users SET id = 1', 'users'));
    }

    public function testDefinitionRejectsUnknownTables(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        (new MutationTableLookup($registry))->definition('UPDATE missing SET id = 1', 'missing');
    }

    public function testAssertTableWasNotRemovedRejectsDroppedTables(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $registry->markRemoved('users');
        $this->expectException(\ZtdQuery\Exception\UnsupportedSqlException::class);
        (new MutationTableLookup($registry))->assertTableWasNotRemoved('INSERT INTO users(id) VALUES (1)', 'users');
    }

    public function testAssertTableWasNotRemovedPermitsAnActiveSchema(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], ['id'], []);
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        (new MutationTableLookup($registry))->assertTableWasNotRemoved('INSERT INTO users(id) VALUES (1)', 'users');
        self::assertSame($definition, $registry->get('users'));
    }

}
