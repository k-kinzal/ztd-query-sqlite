<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Transformer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;

#[CoversClass(SqliteTransformer::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\FullTextColumns::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\MatchExpressionRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Index\IndexHintTokens::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\CastTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueExpressionRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueLiteralRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\SqlEdits::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertConflictPredicate::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertExpressionBinder::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\SqliteNativeUpsertProjector::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer::class)]
#[UsesClass(DeleteTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer::class)]
#[UsesClass(InsertTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Insert\InsertProjectionBuilder::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Select\ShadowCteRenderer::class)]
#[UsesClass(UpdateTransformer::class)]
final class SqliteTransformerTest extends TestCase
{
    public function testTransformSelect(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);

        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('WITH', $result);
    }

    public function testTransformInsert(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO users (id, name) VALUES (1, 'Alice')", $tables);
        self::assertStringContainsString('SELECT', $result);
    }

    public function testTransformUpdate(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("UPDATE users SET name = 'Bob' WHERE id = 1", $tables);
        self::assertStringContainsString('SELECT', $result);
    }

    public function testTransformDelete(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('DELETE FROM users WHERE id = 1', $tables);
        self::assertStringContainsString('SELECT', $result);
    }

    public function testTransformUnsupportedThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('CREATE TABLE t (id INTEGER)', []);
    }

    public function testTransformEmptyThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('', []);
    }

    public function testTransformWithEmptyTablesReturnsOriginal(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);

        $result = $transformer->transform('SELECT * FROM users', []);
        self::assertSame('SELECT * FROM users', $result);
    }

    public function testCommitRewriteStateKeepsAllocatedIdentitiesAcrossProjections(): void
    {
        $parser = new SqliteParser();
        $select = new SelectTransformer();
        $transformer = new SqliteTransformer(
            $parser,
            $select,
            new InsertTransformer($parser, $select),
            new UpdateTransformer($parser, $select),
            new DeleteTransformer($parser, $select),
        );
        $tables = ['users' => ['rows' => [], 'columns' => ['id', 'name'], 'columnTypes' => [], 'identityStrategies' => ['id' => \ZtdQuery\Schema\Key\IdentityGenerationStrategy::MaxValue]]];
        $first = $transformer->transform("INSERT INTO users(name) VALUES ('Alice')", $tables);
        $transformer->commitRewriteState();
        $second = $transformer->transform("INSERT INTO users(name) VALUES ('Bob')", $tables);
        self::assertStringContainsString('1 AS "id"', $first);
        self::assertStringContainsString('2 AS "id"', $second);
    }

}
