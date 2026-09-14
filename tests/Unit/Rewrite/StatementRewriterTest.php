<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\StatementRewriter;

#[CoversClass(StatementRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\AddColumnResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\AlteredTableProjection::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\ColumnDefinitionEditor::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\DropColumnResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\RenameResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Resolution\InsertMutationResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Resolution\RowMutationResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Resolution\TableMutationResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Attach\AttachTokens::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Returning\ReturningItemParser::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Context\ReferencedTableLookup::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Context\TableContextBuilder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteDependencies::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteHeaderParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CtePrefixMerger::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteReferences::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\FullTextColumns::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\MatchExpressionRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Index\IndexHintTokens::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\CastTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueExpressionRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueLiteralRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\SqlEdits::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertConflictPredicate::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertExpressionBinder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\ColumnDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\TableBodyParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\TableDefinitionBuilder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\VirtualTableParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyEntryParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyTokens::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\SqliteColumnTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\SqliteForeignKeyDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\SqliteNativeUpsertProjector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\SqliteUpsertExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\View\SqliteViewShadowRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Insert\InsertProjectionBuilder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Select\ShadowCteRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer::class)]
final class StatementRewriterTest extends TestCase
{
    public function testRewriteStatementUsesShadowRowsForReads(): void
    {
        $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
        $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
        $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer(
            $parser,
            $select,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select),
        );
        $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
        $store = new \ZtdQuery\Shadow\ShadowStore();
        $registry->register('users', new \ZtdQuery\Schema\TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store->set('users', [['id' => 7]]);
        $rewriter = new StatementRewriter(
            new \ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser),
            new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser),
            $parser,
            $registry,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser(),
            $store,
            $transformer,
            new \ZtdQuery\Schema\ViewDefinitionSet(),
        );
        $plan = $rewriter->rewriteStatement('SELECT id FROM users', 'SELECT id FROM users');
        self::assertSame(\ZtdQuery\Rewrite\QueryKind::READ, $plan->kind());
        self::assertNull($plan->mutation());
        $result = (new PDO('sqlite::memory:'))->query($plan->sql());
        self::assertNotFalse($result);
        self::assertSame(7, $result->fetchColumn());
    }

    public function testRewriteStatementSimulatesWritesWithoutApplyingThem(): void
    {
        $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
        $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
        $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer(
            $parser,
            $select,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select),
        );
        $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
        $store = new \ZtdQuery\Shadow\ShadowStore();
        $registry->register('users', new \ZtdQuery\Schema\TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store->set('users', [['id' => 7]]);
        $rewriter = new StatementRewriter(
            new \ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser),
            new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser),
            $parser,
            $registry,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser(),
            $store,
            $transformer,
            new \ZtdQuery\Schema\ViewDefinitionSet(),
        );
        $plan = $rewriter->rewriteStatement('INSERT INTO users(id) VALUES (8)', 'INSERT INTO users(id) VALUES (8)');
        self::assertSame(\ZtdQuery\Rewrite\QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
        self::assertSame([['id' => 7]], $store->get('users'));
    }

    public function testRewriteStatementRejectsUnknownReadSources(): void
    {
        $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
        $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
        $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer(
            $parser,
            $select,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select),
        );
        $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
        $store = new \ZtdQuery\Shadow\ShadowStore();
        $registry->register('users', new \ZtdQuery\Schema\TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store->set('users', [['id' => 7]]);
        $rewriter = new StatementRewriter(
            new \ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser),
            new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser),
            $parser,
            $registry,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser(),
            $store,
            $transformer,
            new \ZtdQuery\Schema\ViewDefinitionSet(),
        );
        $this->expectException(\ZtdQuery\Exception\UnknownSchemaException::class);
        $rewriter->rewriteStatement('SELECT * FROM missing', 'SELECT * FROM missing');
    }

}
