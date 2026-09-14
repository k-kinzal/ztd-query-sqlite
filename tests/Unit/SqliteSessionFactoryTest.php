<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Config\ZtdConfig;
use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Connection\StatementInterface;
use ZtdQuery\Platform\Sqlite\Connection\Parameter\SqlitePdoParameterBindingCompiler;
use ZtdQuery\Platform\Sqlite\Connection\Result\SqlitePdoResultColumnTypeResolver;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaReflector;
use ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteSessionFactory;

#[CoversClass(SqliteSessionFactory::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Connection\Parameter\ParameterReplacements::class)]
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
#[UsesClass(SqliteInMemoryAttachStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteMutationResolver::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\SqliteNativeUpsertProjector::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SqlitePdoParameterBindingCompiler::class)]
#[UsesClass(SqlitePdoResultColumnTypeResolver::class)]
#[UsesClass(SqliteQueryGuard::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser::class)]
#[UsesClass(SqliteRewriter::class)]
#[UsesClass(SqliteSchemaParser::class)]
#[UsesClass(SqliteSchemaReflector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Transaction\SqliteTransactionStatementParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\SqliteUpsertExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\View\SqliteViewDefinitionParser::class)]
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
final class SqliteSessionFactoryTest extends TestCase
{
    public function testCreateRegistersReflectedViews(): void
    {
        $empty = self::createStub(StatementInterface::class);
        $empty->method('fetchAll')->willReturn([]);
        $views = self::createStub(StatementInterface::class);
        $views->method('fetchAll')->willReturn([
            ['name' => 'active_users', 'sql' => 'CREATE VIEW active_users AS SELECT 1 AS id'],
        ]);
        $connection = self::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturnCallback(
            static fn (string $sql): StatementInterface => str_contains($sql, "type='view'") ? $views : $empty,
        );

        $session = (new SqliteSessionFactory())->create($connection, ZtdConfig::default());

        self::assertSame(
            "WITH \"active_users\" AS (SELECT 1 AS id)\nSELECT * FROM active_users",
            $session->rewrite('SELECT * FROM active_users')->sql(),
        );
    }

    public function testCreateReturnsSession(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $config = new ZtdConfig();
        $factory = new SqliteSessionFactory();
        $session = $factory->create($connection, $config);

        self::assertTrue($session->isEnabled());
        self::assertInstanceOf(SqlitePdoParameterBindingCompiler::class, $session->parameterBindingCompiler());
        self::assertInstanceOf(SqlitePdoResultColumnTypeResolver::class, $session->resultColumnTypeResolver());
    }

    public function testCreateWithExistingTablesRegistersDefinitions(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'users', 'sql' => 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $config = new ZtdConfig();
        $factory = new SqliteSessionFactory();
        $session = $factory->create($connection, $config);

        self::assertTrue($session->isEnabled());

        $plan = $session->rewrite('SELECT * FROM users');
        self::assertStringContainsString('WITH', $plan->sql());
    }

    public function testCreateWithUnparseableSchemaSkipsTable(): void
    {
        $statement = static::createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([
            ['name' => 'bad', 'sql' => 'not valid sql'],
            ['name' => 'good', 'sql' => 'CREATE TABLE good (id INTEGER PRIMARY KEY)'],
        ]);

        $connection = static::createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn($statement);

        $config = new ZtdConfig();
        $factory = new SqliteSessionFactory();
        $session = $factory->create($connection, $config);

        self::assertTrue($session->isEnabled());
    }
}
