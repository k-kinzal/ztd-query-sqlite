<?php

declare(strict_types=1);

namespace Tests\Unit\Transformer;

use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(UpdateTransformer::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
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
            ],
        ];

        $result = $transformer->transform("UPDATE users SET name = 'Bob' WHERE id = 1", $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString("'Bob'", $result);
        self::assertStringContainsString('"name"', $result);
        self::assertStringContainsString('WHERE id = 1', $result);
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

        $projection = $transformer->buildProjection("UPDATE t SET a = 1", 't', []);
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

        $this->expectException(\RuntimeException::class);
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

        $projection = $transformer->buildProjection("UPDATE users SET", 'users', []);
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
