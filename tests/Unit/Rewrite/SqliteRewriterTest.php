<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Schema\View\SqliteViewDefinitionParser;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation;
use ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;
use ZtdQuery\Rewrite\QueryKind;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinitionSet;
use ZtdQuery\Shadow\Mutation\Row\DeleteMutation;
use ZtdQuery\Shadow\Mutation\Row\InsertMutation;
use ZtdQuery\Shadow\Mutation\Row\ReplaceMutation;
use ZtdQuery\Shadow\Mutation\Row\UpdateMutation;
use ZtdQuery\Shadow\Mutation\Table\CreateTableMutation;
use ZtdQuery\Shadow\Mutation\Table\DropTableMutation;
use ZtdQuery\Shadow\Mutation\UpsertMutation;
use ZtdQuery\Shadow\ShadowStore;
use ZtdQuery\Shadow\ShadowTableState;

#[CoversClass(SqliteRewriter::class)]
#[UsesClass(AlterTableMutation::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Transaction\TransactionTokens::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\StatementRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertConflictPredicate::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertExpressionBinder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\ColumnDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\TableBodyParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\TableDefinitionBuilder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Create\VirtualTableParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyEntryParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyTokens::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\SqliteColumnTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\SqliteForeignKeyDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteMutationResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\SqliteNativeUpsertProjector::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SqliteQueryGuard::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement::class)]
#[UsesClass(SqliteReturningProjectionParser::class)]
#[UsesClass(SqliteSchemaParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Transaction\SqliteTransactionStatementParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\SqliteUpsertExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer::class)]
#[UsesClass(SqliteViewDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\View\SqliteViewShadowRenderer::class)]
#[UsesClass(DeleteTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer::class)]
#[UsesClass(InsertTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Insert\InsertProjectionBuilder::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Select\ShadowCteRenderer::class)]
#[UsesClass(SqliteTransformer::class)]
#[UsesClass(UpdateTransformer::class)]
final class SqliteRewriterTest extends \PHPUnit\Framework\TestCase
{
    public function testSelectReturnsReadKind(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertNull($plan->mutation());
        self::assertStringContainsString('SELECT', strtoupper($plan->sql()));
    }

    public function testInsertReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(InsertMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
        self::assertMatchesRegularExpression('/^(?:WITH\b|SELECT\b)/i', $plan->sql());
    }

    public function testUpdateReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(UpdateMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
        self::assertStringContainsString('"users"."id" AS "__ztd_original_id"', $plan->sql());
        self::assertMatchesRegularExpression('/^(?:WITH\b|SELECT\b)/i', $plan->sql());
    }

    public function testDeleteReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(DeleteMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
        self::assertMatchesRegularExpression('/^(?:WITH\b|SELECT\b)/i', $plan->sql());
    }

    public function testCreateTableReturnsDdlSimulated(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        self::assertSame(QueryKind::DDL_SIMULATED, $plan->kind());
        self::assertInstanceOf(CreateTableMutation::class, $plan->mutation());
    }

    public function testDropTableReturnsDdlSimulated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DROP TABLE users');
        self::assertSame(QueryKind::DDL_SIMULATED, $plan->kind());
        self::assertInstanceOf(DropTableMutation::class, $plan->mutation());
    }

    public function testUnsupportedSqlThrowsException(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewrite('CREATE INDEX idx ON users (name)');
    }

    public function testEmptyInputThrowsException(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewrite('');
    }

    public function testRewriteIsDeterministic(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan1 = $rewriter->rewrite('SELECT * FROM users');
        $plan2 = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame($plan1->sql(), $plan2->sql());
        self::assertSame($plan1->kind(), $plan2->kind());
    }

    public function testReadPlanHasNoMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertNull($plan->mutation());
    }

    public function testWritePlanHasNonNullMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
        self::assertInstanceOf(InsertMutation::class, $plan->mutation());
    }

    public function testRewriteOutputIsNonEmpty(): void
    {
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteSchemaParser();
        $rewriterDefinition = $rewriterParser->parse('CREATE TABLE users (id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL)');
        self::assertNotNull($rewriterDefinition);
        $rewriterRegistry->register('users', $rewriterDefinition);
        $rewriter2Store = $rewriterStore;
        $rewriter2Registry = $rewriterRegistry;
        $rewriter2Parser = new SqliteParser();
        $rewriter2SchemaParser = new SqliteSchemaParser();
        $rewriter2SelectTransformer = new SelectTransformer();
        $rewriter2InsertTransformer = new InsertTransformer($rewriter2Parser, $rewriter2SelectTransformer);
        $rewriter2UpdateTransformer = new UpdateTransformer($rewriter2Parser, $rewriter2SelectTransformer);
        $rewriter2DeleteTransformer = new DeleteTransformer($rewriter2Parser, $rewriter2SelectTransformer);
        $rewriter2Transformer = new SqliteTransformer($rewriter2Parser, $rewriter2SelectTransformer, $rewriter2InsertTransformer, $rewriter2UpdateTransformer, $rewriter2DeleteTransformer);
        $rewriter2MutationResolver = new SqliteMutationResolver($rewriter2Store, $rewriter2Registry, $rewriter2SchemaParser, $rewriter2Parser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriter2Parser), $rewriter2Store, $rewriter2Registry, $rewriter2Transformer, $rewriter2MutationResolver, $rewriter2Parser);
        $plan = $rewriter->rewrite('SELECT id, name, email FROM users WHERE id = 1');
        self::assertNotEmpty($plan->sql());
        self::assertStringContainsString('SELECT', strtoupper($plan->sql()));
    }

    public function testInsertRewriteOutputContainsSelect(): void
    {
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteSchemaParser();
        $rewriterDefinition = $rewriterParser->parse('CREATE TABLE users (id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL)');
        self::assertNotNull($rewriterDefinition);
        $rewriterRegistry->register('users', $rewriterDefinition);
        $rewriter2Store = $rewriterStore;
        $rewriter2Registry = $rewriterRegistry;
        $rewriter2Parser = new SqliteParser();
        $rewriter2SchemaParser = new SqliteSchemaParser();
        $rewriter2SelectTransformer = new SelectTransformer();
        $rewriter2InsertTransformer = new InsertTransformer($rewriter2Parser, $rewriter2SelectTransformer);
        $rewriter2UpdateTransformer = new UpdateTransformer($rewriter2Parser, $rewriter2SelectTransformer);
        $rewriter2DeleteTransformer = new DeleteTransformer($rewriter2Parser, $rewriter2SelectTransformer);
        $rewriter2Transformer = new SqliteTransformer($rewriter2Parser, $rewriter2SelectTransformer, $rewriter2InsertTransformer, $rewriter2UpdateTransformer, $rewriter2DeleteTransformer);
        $rewriter2MutationResolver = new SqliteMutationResolver($rewriter2Store, $rewriter2Registry, $rewriter2SchemaParser, $rewriter2Parser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriter2Parser), $rewriter2Store, $rewriter2Registry, $rewriter2Transformer, $rewriter2MutationResolver, $rewriter2Parser);
        $plan = $rewriter->rewrite("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertMatchesRegularExpression('/^(?:WITH\b|SELECT\b)/i', $plan->sql(), 'INSERT rewrite must produce a result-select query starting with SELECT or WITH...SELECT');
    }

    public function testGeneratedExpressionIsPresentBeforeTheFirstShadowWrite(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $definition = (new SqliteSchemaParser())->parse('CREATE TABLE orders (qty INTEGER, total INTEGER GENERATED ALWAYS AS (qty * 2) STORED)');
        self::assertNotNull($definition);
        $registry->register('orders', $definition);
        $rewriterStore = $store;
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $sql = (new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser))->rewrite('SELECT total FROM orders')->sql();
        self::assertStringContainsString('(qty * 2) AS "total"', $sql);
    }

    public function testRegisteredViewIsKnownAndMaterialized(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $schemaParser = new SqliteSchemaParser();
        $definition = $schemaParser->parse('CREATE TABLE users (id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL)');
        self::assertNotNull($definition);
        $registry->register('users', $definition);
        $store->set('users', [['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']]);
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $resolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $views = new ViewDefinitionSet();
        $views->register('active_users', (new SqliteViewDefinitionParser())->fromQuery('SELECT id FROM main.users'));
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser, $views);
        $sql = $rewriter->rewrite('SELECT * FROM active_users')->sql();
        self::assertStringStartsWith('WITH "users" AS', $sql);
        self::assertStringContainsString('"active_users" AS (SELECT id FROM users)', $sql);
        $viewOnlyStore = new ShadowStore();
        $viewOnlyRegistry = new TableDefinitionRegistry();
        $viewOnlyViews = new ViewDefinitionSet();
        $viewOnlyViews->register('constant_view', (new SqliteViewDefinitionParser())->fromQuery('SELECT 1 AS id'));
        $viewOnlyResolver = new SqliteMutationResolver($viewOnlyStore, $viewOnlyRegistry, $schemaParser, $parser);
        $viewOnlyRewriter = new SqliteRewriter(new SqliteQueryGuard($parser), $viewOnlyStore, $viewOnlyRegistry, $transformer, $viewOnlyResolver, $parser, $viewOnlyViews);
        $this->expectException(UnknownSchemaException::class);
        $viewOnlyRewriter->rewrite('SELECT * FROM missing_table');
    }

    public function testCteReferencesAreMatchedCaseInsensitivelyDuringSchemaValidation(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = (new SqliteSchemaParser())->parse('CREATE TABLE known_table (id INTEGER PRIMARY KEY)');
        self::assertNotNull($definition);
        $registry->register('known_table', $definition);
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $plan = (new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser))->rewrite('WITH users AS (SELECT 1 AS id) SELECT * FROM Users');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testUnknownTableAfterDeclaredCteIsRejected(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = (new SqliteSchemaParser())->parse('CREATE TABLE known_table (id INTEGER PRIMARY KEY)');
        self::assertNotNull($definition);
        $registry->register('known_table', $definition);
        $this->expectException(UnknownSchemaException::class);
        $this->expectExceptionMessage('missing_table');
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        (new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser))->rewrite('WITH users AS (SELECT 1 AS id) SELECT * FROM Users JOIN missing_table ON TRUE');
    }

    public function testInMemoryAttachPassesThroughUnchanged(): void
    {
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $sql = "ATTACH DATABASE ':memory:' AS db2";
        $plan = $rewriter->rewrite($sql);
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertSame($sql, $plan->sql());
    }

    public function testPersistentAttachRemainsUnsupported(): void
    {
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewrite("ATTACH 'test.sqlite' AS db2");
    }

    public function testSchemaQualifiedSelectUsesShadowCte(): void
    {
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']]);
        $registry = new TableDefinitionRegistry();
        $definition = (new SqliteSchemaParser())->parse('CREATE TABLE users (id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL)');
        self::assertNotNull($definition);
        $registry->register('users', $definition);
        $rewriterStore = $store;
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $plan = (new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser))->rewrite('SELECT name FROM main.users');
        self::assertStringStartsWith('WITH "users" AS', $plan->sql());
        self::assertStringEndsWith('SELECT name FROM users', $plan->sql());
    }

    public function testReadOnlyDiagnosticsPassThroughUnchanged(): void
    {
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $sql = 'EXPLAIN QUERY PLAN SELECT * FROM users';
        $plan = $rewriter->rewrite($sql);
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertSame($sql, $plan->sql());
    }

    public function testDeleteFromWithoutWhereReturnsWriteSimulated(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(DeleteMutation::class, $plan->mutation());
    }

    public function testReplaceReturnsWriteSimulatedWithMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("REPLACE INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(ReplaceMutation::class, $plan->mutation());
    }

    public function testInsertOrReplaceReturnsWriteSimulatedWithReplaceMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("INSERT OR REPLACE INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(ReplaceMutation::class, $plan->mutation());
    }

    public function testInsertOnConflictReturnsUpsertMutation(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com') ON CONFLICT (id) DO UPDATE SET name = excluded.name");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(UpsertMutation::class, $plan->mutation());
        self::assertStringContainsString('__ztd_upsert_value_0', $plan->sql());
        self::assertStringNotContainsString('excluded.', $plan->sql());
    }

    public function testMultiStatementThrowsException(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewrite('SELECT 1; SELECT 2');
    }

    public function testInsertUsesDefaultsFromRegistryWithoutShadowRows(): void
    {
        $definition = (new SqliteSchemaParser())->parse("CREATE TABLE settings (id INTEGER, label TEXT DEFAULT 'new')");
        self::assertNotNull($definition);
        $registry = new TableDefinitionRegistry();
        $registry->register('settings', $definition);
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite('INSERT INTO settings (id) VALUES (1)');
        self::assertStringContainsString("'new'", $plan->sql());
        self::assertStringContainsString('AS "label"', $plan->sql());
    }

    public function testInsertUsesIdentityStrategyFromRegistryWithoutShadowRows(): void
    {
        $definition = (new SqliteSchemaParser())->parse('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        self::assertNotNull($definition);
        $registry = new TableDefinitionRegistry();
        $registry->register('users', $definition);
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite("INSERT INTO users (name) VALUES ('Alice')");
        self::assertStringContainsString('CAST(1 AS INTEGER) AS "id"', $plan->sql());
    }

    public function testRewriteMultiple(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $multiPlan = $rewriter->rewriteMultiple("SELECT * FROM users; INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'a@b.com')");
        self::assertSame(2, $multiPlan->count());
        self::assertSame(QueryKind::READ, $multiPlan->get(0)?->kind());
        self::assertSame(QueryKind::WRITE_SIMULATED, $multiPlan->get(1)?->kind());
    }

    public function testSelectWithShadowDataIncludesCte(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name', 'email'], ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'], ['id'], ['id', 'name'], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice', 'email' => 'a@b.com']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringStartsWith('WITH', $plan->sql());
        self::assertStringContainsString('"users"', $plan->sql());
        self::assertStringContainsString('SELECT', strtoupper($plan->sql()));
    }

    public function testSelectUnknownTableWithSchemaContextThrows(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testSelectKnownTableNoSchemaContextDoesNotThrow(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM whatever');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testSelectWithShadowStoreOnlyHasSchemaContext(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
    }

    public function testUpdateEnsuresShadowStore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
    }

    public function testDeleteFromWithoutWhereReturnsSqlWithSelectWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users');
        self::assertStringContainsString('FROM "users"', $plan->sql());
    }

    public function testDdlSimulatedReturnsSqlSelectWhere0(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        self::assertSame('SELECT 1 WHERE 0', $plan->sql());
        self::assertSame(QueryKind::DDL_SIMULATED, $plan->kind());
    }

    public function testAlterTableUsesShadowedMigrationSelect(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('people', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->set('people', [['id' => 1, 'name' => 'Alice']]);
        $rewriterStore = $store;
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite('ALTER TABLE people ADD COLUMN age INTEGER DEFAULT 7');
        self::assertSame(QueryKind::DDL_SIMULATED, $plan->kind());
        self::assertInstanceOf(AlterTableMutation::class, $plan->mutation());
        self::assertStringContainsString('WITH "people" AS', $plan->sql());
        self::assertStringContainsString('SELECT "id", "name", 7 AS "age" FROM "people"', $plan->sql());
    }

    public function testRemovedTableIsShadowedInsteadOfFallingThrough(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('people', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $registry->markRemoved('people');
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite('SELECT id, name FROM people');
        self::assertStringContainsString('WITH "people" AS', $plan->sql());
        self::assertStringContainsString('WHERE 0', $plan->sql());
    }

    public function testRewriteMultipleEmptyThrows(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnsupportedSqlException::class);
        $rewriter->rewriteMultiple('');
    }

    public function testSelectWithRegistryOnlyBuildTableContext(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
        self::assertStringContainsString('WHERE 0', $plan->sql());
    }

    public function testSelectWithShadowDataColumnsInferred(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"users"', $plan->sql());
        self::assertStringContainsString('"id"', $plan->sql());
        self::assertStringContainsString('"name"', $plan->sql());
    }

    public function testDeleteWithWhereProducesTransformedSql(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertStringContainsString('SELECT', $plan->sql());
        self::assertStringContainsString('WHERE id = 1', $plan->sql());
    }

    public function testDeleteEnsuresShadowStore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(DeleteMutation::class, $plan->mutation());
    }

    public function testSelectExistingInShadowStoreIsNotUnknown(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1]]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testInsertWithShadowDataProducesTransformedSql(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("INSERT INTO users (id, name) VALUES (2, 'Bob')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertStringContainsString('SELECT', $plan->sql());
        self::assertNotNull($plan->mutation());
    }

    public function testBuildTableContextMergesColumnsFromMultipleRows(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1, 'name' => 'Alice']]);
        $store->insert('users', [['id' => 2, 'name' => 'Bob', 'email' => 'b@b.com']]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"id"', $plan->sql());
        self::assertStringContainsString('"name"', $plan->sql());
        self::assertStringContainsString('"email"', $plan->sql());
    }

    public function testBuildTableContextUsesDefinitionColumns(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1]]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"name"', $plan->sql());
    }

    public function testBuildTableContextRegistryOnlyTableIncluded(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $registry->register('orders', new TableDefinition(['id', 'amount'], ['id' => 'INTEGER', 'amount' => 'REAL'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertStringContainsString('"users"', $plan->sql());
    }

    public function testSelectTableInShadowStoreNotUnknown(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $registry = new TableDefinitionRegistry();
        $registry->register('orders', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testDeleteFromQuotedTableWithoutWhereReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('my_table', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('my_table');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM "my_table"');
        self::assertStringContainsString('FROM "my_table"', $plan->sql());
    }

    public function testDeleteFromWithSemicolonAndWhitespace(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users ;');
        self::assertStringContainsString('FROM "users"', $plan->sql());
    }

    public function testUpdateEnsuresShadowStoreCalledOnTarget(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'val'], ['id' => 'INTEGER', 'val' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("UPDATE t SET val = 'x' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertSame([], $store->get('t'));
    }

    public function testDeleteEnsuresShadowStoreCalledOnTarget(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id', 'val'], ['id' => 'INTEGER', 'val' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM t WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertSame([], $store->get('t'));
    }

    public function testBuildTableContextShadowStoreEmptyRowsNoDefinitionPassesThrough(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT 1');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testHasSchemaContextWithRegistryOnly(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testHasSchemaContextWithShadowStoreOnly(): void
    {
        $store = new ShadowStore();
        $store->ensure('users');
        $store->insert('users', [['id' => 1]]);
        $registry = new TableDefinitionRegistry();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $this->expectException(UnknownSchemaException::class);
        $rewriter->rewrite('SELECT * FROM nonexistent');
    }

    public function testDeleteFromBacktickQuotedTableReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('t');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM `t`');
        self::assertStringContainsString('FROM "t"', $plan->sql());
    }

    public function testDeleteFromBracketQuotedTableReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('t', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('t');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM [t]');
        self::assertStringContainsString('FROM "t"', $plan->sql());
    }

    public function testUpdateEnsuresShadowStoreForTargetTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
        self::assertSame([], $store->get('users'));
    }

    public function testDeleteEnsuresShadowStoreForTargetTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertNotNull($plan->mutation());
    }

    public function testDeleteFromLowercaseReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('delete from users');
        self::assertStringContainsString('FROM "users"', $plan->sql());
    }

    public function testBuildTableContextIncludesMultipleTablesFromRegistry(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $registry->register('orders', new TableDefinition(['oid', 'uid'], ['oid' => 'INTEGER', 'uid' => 'INTEGER'], ['oid'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users JOIN orders ON users.id = orders.uid');
        self::assertSame(QueryKind::READ, $plan->kind());
    }

    public function testBuildTableContextWithShadowStoreColumnsInferred(): void
    {
        $registry = new TableDefinitionRegistry();
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
        self::assertStringContainsString('"id"', $plan->sql());
        self::assertStringContainsString('"name"', $plan->sql());
    }

    public function testBuildTableContextSkipsAlreadyAddedFromShadowStore(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $store->set('users', [['id' => 1, 'name' => 'Alice']]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('WITH', $plan->sql());
        self::assertSame(1, substr_count($plan->sql(), '"users" AS'));
    }

    public function testInsertDoesNotEnsureShadowStoreForTargetTable(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("INSERT INTO users (id, name) VALUES (1, 'Alice')");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
    }

    public function testDeleteFromWithCommentsReturnsSqlWhere0(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('users');
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('/* comment */ DELETE FROM users');
        self::assertStringContainsString('FROM "users"', $plan->sql());
    }

    public function testSelectRewritesTableAfterBlockComment(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->set('users', [['id' => 1]]);
        $rewriterStore = $store;
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite('SELECT * FROM/* table */users');
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('"users" AS', $plan->sql());
    }

    public function testSelectIgnoresSqlKeywordsInsideLeadingLineComment(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $store->set('users', [['id' => 1]]);
        $rewriterStore = $store;
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $sql = "-- SELECT * FROM other_table WHERE DELETE UPDATE INSERT\nSELECT * FROM users";
        $plan = $rewriter->rewrite($sql);
        self::assertSame(QueryKind::READ, $plan->kind());
        self::assertStringContainsString('"users" AS', $plan->sql());
        self::assertStringNotContainsString('"other_table" AS', $plan->sql());
    }

    public function testInsertResolvesTargetAfterBlockComment(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite('INSERT INTO/* table */users (id) VALUES (1)');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(InsertMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
    }

    public function testUpdateResolvesTargetAfterBlockComment(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite('UPDATE/* table */users SET id = 2 WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(UpdateMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
    }

    public function testDeleteResolvesTargetAfterBlockComment(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id'], ['id' => 'INTEGER'], ['id'], [], []));
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $plan = $rewriter->rewrite('DELETE FROM/* table */users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertInstanceOf(DeleteMutation::class, $plan->mutation());
        self::assertSame('users', $plan->mutation()->tableName());
    }

    public function testUpdateEnsuresShadowStoreEntry(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite("UPDATE users SET name = 'Bob' WHERE id = 1");
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertArrayHasKey('users', $store->getAll());
    }

    public function testDeleteEnsuresShadowStoreEntry(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('DELETE FROM users WHERE id = 1');
        self::assertSame(QueryKind::WRITE_SIMULATED, $plan->kind());
        self::assertArrayHasKey('users', $store->getAll());
    }

    public function testSelectWithMultipleRegisteredTablesIncludesAll(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $registry->register('orders', new TableDefinition(['id', 'user_id'], ['id' => 'INTEGER', 'user_id' => 'INTEGER'], ['id'], [], []));
        $store = new ShadowStore();
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertSame(QueryKind::READ, $plan->kind());
        $sql = $plan->sql();
        self::assertStringContainsString('"users"', $sql);
        self::assertStringContainsString('"orders"', $sql);
    }

    public function testSelectWithShadowStoreAndRegistryTablesMerged(): void
    {
        $registry = new TableDefinitionRegistry();
        $registry->register('users', new TableDefinition(['id', 'name'], ['id' => 'INTEGER', 'name' => 'TEXT'], ['id'], [], []));
        $store = new ShadowStore();
        $store->ensure('orders');
        $store->set('orders', [['id' => 1, 'user_id' => 1]]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $schemaParser = new SqliteSchemaParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        $plan = $rewriter->rewrite('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertSame(QueryKind::READ, $plan->kind());
        $sql = $plan->sql();
        self::assertStringContainsString('"users"', $sql);
        self::assertStringContainsString('"orders"', $sql);
    }

    public function testUpdateDoesNotPromoteMaterializedUnknownTable(): void
    {
        $store = new ShadowStore();
        $store->insert('late_table', [['id' => 1, 'name' => 'Alice']]);
        $rewriterStore = $store;
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        try {
            $rewriter->rewrite("UPDATE late_table SET name = 'Bob' WHERE id = 1");
            self::fail('Expected an unknown schema exception.');
        } catch (UnknownSchemaException) {
            self::assertSame(ShadowTableState::Materialized, $store->state('late_table'));
        }
    }

    public function testTransactionStatementRestoresSnapshotOnRollback(): void
    {
        $store = new ShadowStore();
        $store->set('users', [['id' => 1]]);
        $rewriterStore = $store;
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $transactions = new \ZtdQuery\Shadow\ShadowTransactions($store);
        $begin = $rewriter->transactionStatement('BEGIN');
        $rollback = $rewriter->transactionStatement('ROLLBACK');
        self::assertNotNull($begin);
        self::assertNotNull($rollback);
        $begin->apply($transactions);
        $store->set('users', [['id' => 2]]);
        $rollback->apply($transactions);
        self::assertSame([['id' => 1]], $store->get('users'));
        self::assertNull($rewriter->transactionStatement('SELECT 1'));
    }

    public function testSplitStatementsPreservesQuotedSemicolons(): void
    {
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        self::assertSame(["SELECT 'a;b'", 'SELECT 2'], $rewriter->splitStatements("SELECT 'a;b'; SELECT 2;"));
    }

    public function testEmptyResultSelectProducesNoRows(): void
    {
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = new TableDefinitionRegistry();
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $statement = (new PDO('sqlite::memory:'))->query($rewriter->emptyResultSelect());
        self::assertNotFalse($statement);
        self::assertSame([], $statement->fetchAll());
    }

    public function testCommitRewriteStateKeepsGeneratedIdentitiesAcrossRewrites(): void
    {
        $registry = new TableDefinitionRegistry();
        $definition = (new SqliteSchemaParser())->parse('CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT)');
        self::assertNotNull($definition);
        $registry->register('users', $definition);
        $rewriterStore = new ShadowStore();
        $rewriterRegistry = $registry;
        $rewriterParser = new SqliteParser();
        $rewriterSchemaParser = new SqliteSchemaParser();
        $rewriterSelectTransformer = new SelectTransformer();
        $rewriterInsertTransformer = new InsertTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterUpdateTransformer = new UpdateTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterDeleteTransformer = new DeleteTransformer($rewriterParser, $rewriterSelectTransformer);
        $rewriterTransformer = new SqliteTransformer($rewriterParser, $rewriterSelectTransformer, $rewriterInsertTransformer, $rewriterUpdateTransformer, $rewriterDeleteTransformer);
        $rewriterMutationResolver = new SqliteMutationResolver($rewriterStore, $rewriterRegistry, $rewriterSchemaParser, $rewriterParser);
        $rewriter = new SqliteRewriter(new SqliteQueryGuard($rewriterParser), $rewriterStore, $rewriterRegistry, $rewriterTransformer, $rewriterMutationResolver, $rewriterParser);
        $first = $rewriter->rewrite("INSERT INTO users(name) VALUES ('Alice')");
        $rewriter->commitRewriteState();
        $second = $rewriter->rewrite("INSERT INTO users(name) VALUES ('Bob')");
        self::assertStringContainsString('CAST(1 AS INTEGER) AS "id"', $first->sql());
        self::assertStringContainsString('CAST(2 AS INTEGER) AS "id"', $second->sql());
    }
}
