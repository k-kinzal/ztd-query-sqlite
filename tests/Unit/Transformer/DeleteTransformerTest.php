<?php

declare(strict_types=1);

namespace Tests\Unit\Transformer;

use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(DeleteTransformer::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
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
