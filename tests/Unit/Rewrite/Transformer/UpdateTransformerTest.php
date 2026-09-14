<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Transformer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;

#[CoversClass(UpdateTransformer::class)]
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
final class UpdateTransformerTest extends TestCase
{
    public function testTransformSimpleUpdate(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name', 'email'],
                'columnTypes' => [],
                'primaryKeys' => ['id'],
            ],
        ];

        $result = $transformer->transform("UPDATE users SET name = 'Bob' WHERE id = 1", $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString("'Bob'", $result);
        self::assertStringContainsString('"name"', $result);
        self::assertStringContainsString('WHERE id = 1', $result);
        self::assertStringContainsString('"users"."id" AS "__ztd_original_id"', $result);
    }

    public function testTransformUpdateWithMultipleAssignments(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name', 'email'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("UPDATE users SET name = 'Bob', email = 'bob@test.com' WHERE id = 1", $tables);

        self::assertStringContainsString("'Bob'", $result);
        self::assertStringContainsString("'bob@test.com'", $result);
    }

    public function testTransformUpdateWithOrderByAndLimit(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("UPDATE users SET name = 'Bob' WHERE id > 0 ORDER BY id LIMIT 5", $tables);

        self::assertStringContainsString('ORDER BY id', $result);
        self::assertStringContainsString('LIMIT 5', $result);
    }

    public function testTransformThrowsForNonUpdate(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('SELECT * FROM users', []);
    }

    public function testTransformPreservesUnchangedColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name', 'email'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("UPDATE users SET name = 'Bob' WHERE id = 1", $tables);

        self::assertStringContainsString('"users"."id"', $result);
        self::assertStringContainsString('"users"."email"', $result);
    }

    public function testTransformWithoutTargetThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('UPDATE', []);
    }

    public function testBuildProjectionNoColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection('UPDATE t SET a = 1', 't', []);
        self::assertStringContainsString('SELECT', $projection);
    }

    public function testBuildProjectionMeta(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $meta = $transformer->buildProjectionMeta("UPDATE users SET name = 'x' WHERE id = 1", ['id', 'name']);
        self::assertSame('users', $meta['table']);
        self::assertStringContainsString('SELECT', $meta['sql']);
    }

    public function testBuildProjectionMetaWithoutTargetThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $this->expectException(RuntimeException::class);
        $transformer->buildProjectionMeta('UPDATE', []);
    }

    public function testBuildProjectionIncludesAssignmentValues(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection("UPDATE users SET name = 'Bob', email = 'bob@test.com' WHERE id = 1", 'users', ['id', 'name', 'email']);
        self::assertStringContainsString("'Bob' AS \"name\"", $projection);
        self::assertStringContainsString("'bob@test.com' AS \"email\"", $projection);
        self::assertStringContainsString('"users"."id"', $projection);
    }

    public function testBuildProjectionPreservesAliasFromSourceAndIdentityQualifier(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection(
            'UPDATE users AS target SET name = source.name FROM incoming AS source WHERE target.id = source.id',
            'users',
            ['id', 'name'],
            ['id'],
        );

        self::assertSame(
            'SELECT source.name AS "name", "target"."id", "target"."id" AS "__ztd_original_id" FROM "users" AS "target", incoming AS source WHERE target.id = source.id',
            $projection,
        );
    }

    public function testBuildProjectionWithoutAliasOrFromHasNoExtraSourceSyntax(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection(
            "UPDATE users SET name = 'Bob' WHERE id = 1",
            'users',
            ['id', 'name'],
            ['id'],
        );

        self::assertSame(
            'SELECT \'Bob\' AS "name", "users"."id", "users"."id" AS "__ztd_original_id" FROM "users" WHERE id = 1',
            $projection,
        );
    }

    public function testBuildProjectionNoColumnsUsesStarFallback(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection("UPDATE users SET name = 'x'", 'users', []);
        self::assertStringContainsString("'x' AS \"name\"", $projection);
    }

    public function testTransformWithShadowDataProducesCte(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("UPDATE users SET name = 'Bob' WHERE id = 1", $tables);
        self::assertStringContainsString('WITH', $result);
    }

    public function testBuildProjectionAssignedColumnNotDuplicated(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection("UPDATE users SET name = 'Bob' WHERE id = 1", 'users', ['id', 'name']);
        self::assertSame(1, substr_count($projection, '"name"'));
    }

    public function testBuildProjectionNoAssignmentsNoColumnsUsesStar(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection("UPDATE users SET name = 'x'", 'users', []);
        self::assertStringNotContainsString('*', $projection);
    }

    public function testBuildProjectionAllColumnsAssignedNoStarFallback(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection("UPDATE users SET name = 'Bob', id = 2", 'users', ['id', 'name']);
        self::assertStringNotContainsString('*', $projection);
        self::assertStringContainsString("'Bob' AS \"name\"", $projection);
        self::assertStringContainsString('2 AS "id"', $projection);
    }

    public function testBuildProjectionEmptyAssignmentsEmptyColumnsUsesStar(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection('UPDATE users SET', 'users', []);
        self::assertStringContainsString('*', $projection);
    }

    public function testBuildProjectionAssignedColumnNotDuplicatedInSelect(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new UpdateTransformer($parser, $selectTransformer);

        $projection = $transformer->buildProjection("UPDATE users SET name = 'Bob' WHERE id = 1", 'users', ['id', 'name', 'email']);
        self::assertSame(1, substr_count($projection, '"name"'));
        self::assertStringContainsString("'Bob' AS \"name\"", $projection);
        self::assertStringContainsString('"users"."id"', $projection);
        self::assertStringContainsString('"users"."email"', $projection);
        self::assertStringNotContainsString('"users"."name"', $projection);
    }
}
