<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Transformer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;

#[CoversClass(DeleteTransformer::class)]
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
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Select\ShadowCteRenderer::class)]
final class DeleteTransformerTest extends TestCase
{
    public function testTransformSimpleDelete(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name', 'email'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('DELETE FROM users WHERE id = 1', $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('"users"."id"', $result);
        self::assertStringContainsString('WHERE id = 1', $result);
    }

    public function testTransformDeleteWithOrderByAndLimit(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('DELETE FROM users WHERE id > 0 ORDER BY id LIMIT 5', $tables);

        self::assertStringContainsString('ORDER BY id', $result);
        self::assertStringContainsString('LIMIT 5', $result);
    }

    public function testTransformDeleteWithoutColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('DELETE FROM users WHERE id = 1', $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('"users".*', $result);
    }

    public function testTransformThrowsForNonDelete(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('SELECT * FROM users', []);
    }

    public function testTransformWithoutTargetThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('DELETE', []);
    }

    public function testBuildProjectionWithColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection('DELETE FROM users WHERE id = 1', 'users', ['id', 'name']);
        self::assertStringContainsString('"users"."id" AS "id"', $projection);
        self::assertStringContainsString('"users"."name" AS "name"', $projection);
        self::assertStringContainsString('WHERE id = 1', $projection);
    }

    public function testBuildProjectionWithoutColumnsUsesStar(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection('DELETE FROM users WHERE id = 1', 'users', []);
        self::assertStringContainsString('"users".*', $projection);
    }

    public function testBuildProjectionWithOrderByAndLimit(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection('DELETE FROM users WHERE id > 0 ORDER BY id LIMIT 10', 'users', ['id']);
        self::assertStringContainsString('ORDER BY id', $projection);
        self::assertStringContainsString('LIMIT 10', $projection);
    }

    public function testTransformDeleteWithShadowDataIncludesCte(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new DeleteTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('DELETE FROM users WHERE id = 1', $tables);
        self::assertStringContainsString('WITH', $result);
        self::assertStringContainsString('"users"', $result);
    }
}
