<?php

declare(strict_types=1);

namespace Tests\Unit\Transformer;

use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(InsertTransformer::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
final class InsertTransformerTest extends TestCase
{
    public function testTransformInsertValues(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO users (id, name) VALUES (1, 'Alice')", $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('"id"', $result);
        self::assertStringContainsString('"name"', $result);
    }

    public function testTransformInsertMultipleValues(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')", $tables);

        self::assertStringContainsString('UNION ALL', $result);
    }

    public function testTransformInsertSelect(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
            'temp_users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('INSERT INTO users (id, name) SELECT id, name FROM temp_users', $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('temp_users', $result);
    }

    public function testTransformThrowsForNonInsert(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('SELECT * FROM users', []);
    }

    public function testTransformUsesTableContextForMissingColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO users VALUES (1, 'Alice')", $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('"id"', $result);
        self::assertStringContainsString('"name"', $result);
    }

    public function testTransformWithoutTableThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('INSERT', []);
    }

    public function testTransformWithoutColumnsOrContextThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO users VALUES (1, 'Alice')";

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform($sql, []);
    }

    public function testTransformMismatchedColumnCountThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO users (id, name, email) VALUES (1, 'Alice')";

        $this->expectException(\RuntimeException::class);
        $transformer->transform($sql, []);
    }

    public function testTransformNoValuesThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = 'INSERT INTO users (id) DEFAULT VALUES';

        $this->expectException(\RuntimeException::class);
        $transformer->transform($sql, []);
    }

    public function testTransformReplaceInto(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "REPLACE INTO users (id, name) VALUES (1, 'Alice')";

        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('AS "id"', $result);
        self::assertStringContainsString('AS "name"', $result);
    }

    public function testTransformInsertOrReplace(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT OR REPLACE INTO users (id, name) VALUES (1, 'Alice')";

        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('AS "id"', $result);
    }

    public function testTransformValueTrimmed(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO users (id, name) VALUES ( 1 , 'Alice' )";
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('1 AS "id"', $result);
        self::assertStringContainsString("'Alice' AS \"name\"", $result);
    }

    public function testTransformInsertSelectPassesThrough(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = 'INSERT INTO users (id, name) SELECT id, name FROM temp';
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('SELECT id, name FROM temp', $result);
    }

    public function testTransformColumnAsInOutput(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO t (col1, col2) VALUES (10, 20)";
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('10 AS "col1"', $result);
        self::assertStringContainsString('20 AS "col2"', $result);
    }

    public function testTransformThrowsForNonInsertSelectStatement(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('SELECT * FROM t', []);
    }

    public function testTransformThrowsForNoTarget(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('INSERT', []);
    }

    public function testTransformWithTableContextColumnsUsedWhenNoInsertColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            't' => [
                'rows' => [],
                'columns' => ['a', 'b'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO t VALUES (1, 2)", $tables);
        self::assertStringContainsString('1 AS "a"', $result);
        self::assertStringContainsString('2 AS "b"', $result);
    }

    public function testTransformMultipleValueSets(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $result = $transformer->transform("INSERT INTO t (a) VALUES (1), (2), (3)", []);
        self::assertStringContainsString('UNION ALL', $result);
        self::assertSame(2, substr_count($result, 'UNION ALL'));
    }

    public function testTransformWithValuesTrimmed(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $result = $transformer->transform("INSERT INTO t (a) VALUES ( 1 )", []);
        self::assertStringContainsString('1 AS "a"', $result);
    }

    public function testTransformInsertSelectWithColumnsAndShadowData(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('INSERT INTO t (a) SELECT id FROM users', $tables);
        self::assertStringContainsString('SELECT', $result);
    }

    public function testTransformValueTrimMatters(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO t (a) VALUES (  hello  )";
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('hello AS "a"', $result);
        self::assertStringNotContainsString('  hello  ', $result);
    }

    public function testTransformOutputContainsSelectKeyword(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO t (a) VALUES (1)";
        $result = $transformer->transform($sql, []);
        self::assertStringStartsWith('SELECT ', $result);
    }
}
