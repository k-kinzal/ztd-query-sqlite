<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Context\ReferencedTableLookup;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinition;
use ZtdQuery\Schema\ViewDefinitionSet;
use ZtdQuery\Shadow\ShadowStore;

#[CoversClass(ReferencedTableLookup::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteDependencies::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteHeaderParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CtePrefixMerger::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteReferences::class)]
#[UsesClass(SqliteCteShadowComposer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class ReferencedTableLookupTest extends TestCase
{
    public function testFindUnknownTableIgnoresUserCtesAndFindsUnknownJoinSources(): void
    {
        $registry = new TableDefinitionRegistry();
        $store = new ShadowStore();
        $views = new ViewDefinitionSet();
        $lookup = new ReferencedTableLookup(new SqliteCteShadowComposer(), new SqliteParser(), $registry, $store, $views);
        $store->set('users', [['id' => 1]]);
        self::assertNull($lookup->findUnknownTable('WITH missing AS (SELECT 1) SELECT * FROM missing'));
        self::assertSame('orders', $lookup->findUnknownTable('SELECT * FROM users JOIN orders ON users.id = orders.user_id'));
        self::assertNull($lookup->findUnknownTable('DELETE FROM unknown'));
        self::assertNull($lookup->findUnknownTable('SELECT * FROM users'));
    }

    public function testTableExistsRecognizesEachSchemaState(): void
    {
        $registry = new TableDefinitionRegistry();
        $store = new ShadowStore();
        $views = new ViewDefinitionSet();
        $lookup = new ReferencedTableLookup(new SqliteCteShadowComposer(), new SqliteParser(), $registry, $store, $views);
        $store->set('fixture', []);
        $registry->register('known', new TableDefinition(['id'], [], [], [], []));
        $registry->register('removed', new TableDefinition(['id'], [], [], [], []));
        $registry->markRemoved('removed');
        $views->register('view_users', new ViewDefinition('SELECT 1', []));
        self::assertTrue($lookup->tableExists('fixture'));
        self::assertTrue($lookup->tableExists('known'));
        self::assertTrue($lookup->tableExists('removed'));
        self::assertTrue($lookup->tableExists('view_users'));
        self::assertFalse($lookup->tableExists('unknown'));
    }

    public function testHasSchemaContextIncludesViews(): void
    {
        $registry = new TableDefinitionRegistry();
        $store = new ShadowStore();
        $views = new ViewDefinitionSet();
        $lookup = new ReferencedTableLookup(new SqliteCteShadowComposer(), new SqliteParser(), $registry, $store, $views);
        self::assertFalse($lookup->hasSchemaContext());
        $views->register('v', new ViewDefinition('SELECT 1', []));
        self::assertTrue($lookup->hasSchemaContext());
    }

    public function testAssertKnownTablesRejectsUnknownSourcesWhenContextExists(): void
    {
        $registry = new TableDefinitionRegistry();
        $store = new ShadowStore();
        $views = new ViewDefinitionSet();
        $lookup = new ReferencedTableLookup(new SqliteCteShadowComposer(), new SqliteParser(), $registry, $store, $views);
        $registry->register('users', new TableDefinition(['id'], [], [], [], []));
        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $lookup->assertKnownTables('SELECT * FROM missing', 'SELECT * FROM missing');
    }

    public function testAssertKnownTablesPermitsQueriesBeforeFixtureRegistration(): void
    {
        $registry = new TableDefinitionRegistry();
        $store = new ShadowStore();
        $views = new ViewDefinitionSet();
        $lookup = new ReferencedTableLookup(new SqliteCteShadowComposer(), new SqliteParser(), $registry, $store, $views);
        $lookup->assertKnownTables('SELECT * FROM external_table', 'SELECT * FROM external_table');
        self::assertFalse($lookup->hasSchemaContext());
    }

}
