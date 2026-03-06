<?php

declare(strict_types=1);

namespace Tests\Unit\Transformer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Contract\TransformerContractTest;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\IdentifierQuoter;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Rewrite\SqlTransformer;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;

#[CoversClass(SelectTransformer::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
final class SelectTransformerTest extends TransformerContractTest
{
    protected function createTransformer(): SqlTransformer
    {
        return new SelectTransformer();
    }

    protected function selectSql(): string
    {
        return 'SELECT * FROM users WHERE id = 1';
    }

    #[\Override]
    protected function nativeStringType(): string
    {
        return 'TEXT';
    }

    public function testTransformWithNoTablesReturnsOriginal(): void
    {
        $transformer = new SelectTransformer();

        $sql = 'SELECT * FROM users';
        $result = $transformer->transform($sql, []);
        self::assertSame($sql, $result);
    }

    public function testTransformWithEmptyRowsGeneratesEmptyCte(): void
    {
        $transformer = new SelectTransformer();

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'), 'name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);

        self::assertStringContainsString('WITH', $result);
        self::assertStringContainsString('"users"', $result);
        self::assertStringContainsString('WHERE 0', $result);
        self::assertStringContainsString('CAST(NULL AS INTEGER)', $result);
        self::assertStringContainsString('CAST(NULL AS TEXT)', $result);
    }

    public function testTransformWithRowsGeneratesUnionAllCte(): void
    {
        $transformer = new SelectTransformer();

        $tables = [
            'users' => [
                'rows' => [
                    ['id' => 1, 'name' => 'Alice'],
                    ['id' => 2, 'name' => 'Bob'],
                ],
                'columns' => ['id', 'name'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'), 'name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);

        self::assertStringContainsString('WITH', $result);
        self::assertStringContainsString('"users"', $result);
        self::assertStringContainsString('UNION ALL', $result);
        self::assertStringContainsString("CAST('1' AS INTEGER)", $result);
        self::assertStringContainsString("CAST('Alice' AS TEXT)", $result);
    }

    public function testTransformSkipsUnreferencedTables(): void
    {
        $transformer = new SelectTransformer();

        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
            'orders' => [
                'rows' => [['id' => 1, 'total' => 100]],
                'columns' => ['id', 'total'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);

        self::assertStringContainsString('"users"', $result);
        self::assertStringNotContainsString('"orders"', $result);
    }

    public function testTransformWithExistingCte(): void
    {
        $transformer = new SelectTransformer();

        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $sql = 'WITH cte AS (SELECT 1) SELECT * FROM users, cte';
        $result = $transformer->transform($sql, $tables);

        self::assertStringContainsString('WITH', $result);

        self::assertStringContainsString('"users"', $result);
    }

    public function testTransformWithNullValues(): void
    {
        $transformer = new SelectTransformer();

        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => null]],
                'columns' => ['id', 'name'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'), 'name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);

        self::assertStringContainsString('NULL AS "name"', $result);
    }

    public function testTransformUsesDoubleQuotesForIdentifiers(): void
    {
        $transformer = new SelectTransformer();

        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);

        self::assertStringContainsString('"users"', $result);
        self::assertStringContainsString('"id"', $result);
    }

    public function testEmptyCteWithoutTypedColumns(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(NULL AS TEXT) AS "id"', $result);
        self::assertStringContainsString('WHERE 0', $result);
    }

    public function testSingleRowCte(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => [
                    'id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'),
                    'name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
                ],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("CAST('1' AS INTEGER) AS \"id\"", $result);
        self::assertStringContainsString("CAST('Alice' AS TEXT) AS \"name\"", $result);
        self::assertStringNotContainsString('UNION ALL', $result);
    }

    public function testIntValueWithoutType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 42]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(42 AS INTEGER) AS "id"', $result);
    }

    public function testStringValueWithoutType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['name' => 'hello']],
                'columns' => ['name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("CAST('hello' AS TEXT) AS \"name\"", $result);
    }

    public function testBoolValueWithoutType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['active' => true]],
                'columns' => ['active'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('1 AS "active"', $result);
    }

    public function testBoolFalseValueWithoutType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['active' => false]],
                'columns' => ['active'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('0 AS "active"', $result);
    }

    public function testFloatValueWithoutType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['score' => 3.14]],
                'columns' => ['score'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('3.14 AS "score"', $result);
    }

    public function testStringWithSingleQuoteEscaped(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['name' => "it's"]],
                'columns' => ['name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("'it''s'", $result);
    }

    public function testIntValueWithType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 42]],
                'columns' => ['id'],
                'columnTypes' => [
                    'id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'),
                ],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("CAST('42' AS INTEGER) AS \"id\"", $result);
    }

    public function testBoolValueWithType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['active' => true]],
                'columns' => ['active'],
                'columnTypes' => [
                    'active' => new ColumnType(ColumnTypeFamily::BOOLEAN, 'BOOLEAN'),
                ],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("CAST('1' AS INTEGER) AS \"active\"", $result);
    }

    public function testFloatValueWithType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['score' => 3.14]],
                'columns' => ['score'],
                'columnTypes' => [
                    'score' => new ColumnType(ColumnTypeFamily::FLOAT, 'REAL'),
                ],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("CAST('3.14' AS REAL) AS \"score\"", $result);
    }

    public function testColumnsInferredFromRows(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['a' => 1, 'b' => 2]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"a"', $result);
        self::assertStringContainsString('"b"', $result);
    }

    public function testColumnsInferredFromMultipleRows(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['a' => 1], ['a' => 2, 'b' => 3]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"a"', $result);
        self::assertStringContainsString('"b"', $result);
    }

    public function testTableNotInSqlSkipped(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT 1', $tables);
        self::assertSame('SELECT 1', $result);
    }

    public function testEmptyColumnsAndEmptyRowsSkipped(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertSame('SELECT * FROM users', $result);
    }

    public function testUnsupportedValueTypeThrows(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['data' => [1, 2, 3]]],
                'columns' => ['data'],
                'columnTypes' => [],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $transformer->transform('SELECT * FROM users', $tables);
    }

    public function testObjectWithToString(): void
    {
        $transformer = new SelectTransformer();
        $obj = new class () {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $tables = [
            'users' => [
                'rows' => [['val' => $obj]],
                'columns' => ['val'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('stringified', $result);
    }

    public function testMultipleRowsCte(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [
                    ['id' => 1, 'name' => 'Alice'],
                    ['id' => 2, 'name' => 'Bob'],
                    ['id' => 3, 'name' => 'Charlie'],
                ],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('UNION ALL', $result);
        self::assertSame(2, substr_count($result, 'UNION ALL'));
    }

    public function testMultipleTablesCte(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
            'orders' => [
                'rows' => [['id' => 10]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users JOIN orders ON users.id = orders.id', $tables);
        self::assertStringContainsString('"users"', $result);
        self::assertStringContainsString('"orders"', $result);
    }

    public function testEmptyRowsWithTypedColumnsIntegerCast(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(NULL AS INTEGER) AS "id"', $result);
    }

    public function testCteOutputContainsAsKeyword(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"users" AS (', $result);
    }

    public function testNullValueWithTypedColumn(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => null]],
                'columns' => ['id'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('NULL AS "id"', $result);
    }

    public function testSerializableObjectWithType(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['data' => [1, 2]]],
                'columns' => ['data'],
                'columnTypes' => ['data' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(', $result);
    }

    public function testExistingWithClauseAppendsCte(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $sql = 'WITH cte AS (SELECT 1) SELECT * FROM users, cte';
        $result = $transformer->transform($sql, $tables);
        self::assertStringContainsString('"users" AS (', $result);
        self::assertSame(1, substr_count($result, 'WITH '));
    }

    public function testRowsWithNoColumnsNoTypeThrows(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"id"', $result);
    }

    public function testEmptyCteOutputFormat(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"users" AS (SELECT ', $result);
        self::assertStringContainsString(' WHERE 0)', $result);
    }

    public function testRowCteOutputFormat(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"users" AS (SELECT ', $result);
        self::assertStringContainsString(')', $result);
    }

    public function testDefaultCastRendererAndQuoterUsed(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"users"', $result);
        self::assertStringContainsString('"id"', $result);
    }

    public function testExplicitCastRendererAndQuoter(): void
    {
        $transformer = new SelectTransformer(new SqliteCastRenderer(), new SqliteIdentifierQuoter());
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"users"', $result);
    }

    public function testCteWithExistingWithPrependsCtes(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'orders' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $sql = 'WITH existing AS (SELECT 1) SELECT * FROM orders, existing';
        $result = $transformer->transform($sql, $tables);
        self::assertStringContainsString('"orders" AS (', $result);
        self::assertStringContainsString('existing AS (SELECT 1)', $result);
    }

    public function testMultipleCtesSeparatedByComma(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
            'orders' => [
                'rows' => [['oid' => 10]],
                'columns' => ['oid'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users, orders', $tables);
        self::assertStringContainsString('"users" AS (', $result);
        self::assertStringContainsString('"orders" AS (', $result);
        self::assertStringContainsString(',', $result);
    }

    public function testRowsWithColumnsInferredFromMultipleRowsDifferentKeys(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['a' => 1], ['b' => 2]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"a"', $result);
        self::assertStringContainsString('"b"', $result);
    }

    public function testEmptyRowsNoColumnsNoCteContinued(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => [],
                'columnTypes' => [],
            ],
            'orders' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users, orders', $tables);
        self::assertStringContainsString('"orders"', $result);
    }

    public function testNoColumnsWithRowsUsesRowKeysForCte(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['x' => 1, 'y' => 2], ['x' => 3, 'y' => 4]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('UNION ALL', $result);
        self::assertStringContainsString('"x"', $result);
        self::assertStringContainsString('"y"', $result);
    }

    public function testStringValueWithTypeRenderedAsCast(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['name' => 'Alice']],
                'columns' => ['name'],
                'columnTypes' => ['name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("CAST('Alice' AS TEXT) AS \"name\"", $result);
    }

    public function testWithClauseCommentBeforeWith(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $sql = "/* comment */\nWITH cte AS (SELECT 1) SELECT * FROM users, cte";
        $result = $transformer->transform($sql, $tables);
        self::assertStringContainsString('"users" AS (', $result);
    }

    public function testNullValueWithoutTypeReturnsNull(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['a' => null]],
                'columns' => ['a'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('NULL AS "a"', $result);
    }

    public function testFormatValueBoolTrue(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['flag' => true]],
                'columns' => ['flag'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('1 AS "flag"', $result);
    }

    public function testFormatValueBoolFalse(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['flag' => false]],
                'columns' => ['flag'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('0 AS "flag"', $result);
    }

    public function testFormatValueFloat(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['price' => 3.14]],
                'columns' => ['price'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('3.14 AS "price"', $result);
    }

    public function testFormatValueWithTypeUsesTypeCast(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['age' => 25]],
                'columns' => ['age'],
                'columnTypes' => ['age' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(', $result);
        self::assertStringContainsString('AS "age"', $result);
    }

    public function testFormatValueWithTypeBoolCast(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['active' => true]],
                'columns' => ['active'],
                'columnTypes' => ['active' => new ColumnType(ColumnTypeFamily::BOOLEAN, 'BOOLEAN')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(', $result);
    }

    public function testFormatValueWithTypeFloatCast(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['val' => 1.5]],
                'columns' => ['val'],
                'columnTypes' => ['val' => new ColumnType(ColumnTypeFamily::FLOAT, 'FLOAT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(', $result);
    }

    public function testTableNotMentionedInSqlIsSkipped(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
            'orders' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"users"', $result);
        self::assertStringNotContainsString('"orders"', $result);
    }

    public function testCteColumnInferenceFromMultipleRowsWithDifferentKeys(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['a' => 1], ['a' => 2, 'b' => 3]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"a"', $result);
        self::assertStringContainsString('"b"', $result);
    }

    public function testQuoteValueEscapesSingleQuotes(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['name' => "O'Brien"]],
                'columns' => ['name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString("O''Brien", $result);
    }

    public function testFormatValueObjectWithToString(): void
    {
        $transformer = new SelectTransformer();
        $obj = new class () {
            public function __toString(): string
            {
                return 'stringified';
            }
        };
        $tables = [
            'users' => [
                'rows' => [['val' => $obj]],
                'columns' => ['val'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('stringified', $result);
    }

    public function testFormatValueWithTypeObjectUsesSerialized(): void
    {
        $transformer = new SelectTransformer();
        $obj = new \stdClass();
        $tables = [
            'users' => [
                'rows' => [['val' => $obj]],
                'columns' => ['val'],
                'columnTypes' => ['val' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CAST(', $result);
    }

    public function testExistingWithClausePrependsCtes(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('WITH existing AS (SELECT 1) SELECT * FROM users, existing', $tables);
        self::assertStringContainsString('"users" AS', $result);
        self::assertStringContainsString('existing AS (SELECT 1)', $result);
    }

    public function testConstructorWithCustomCastRendererUsesIt(): void
    {
        $castRenderer = static::createStub(CastRenderer::class);
        $castRenderer->method('renderNullCast')->willReturn('CUSTOM_NULL_EXPR');
        $castRenderer->method('renderCast')->willReturn('CUSTOM_CAST_EXPR');

        $transformer = new SelectTransformer($castRenderer, null);
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CUSTOM_NULL_EXPR', $result);
    }

    public function testConstructorWithCustomQuoterUsesIt(): void
    {
        $quoter = static::createStub(IdentifierQuoter::class);
        $quoter->method('quote')->willReturn('CUSTOM_QUOTED');

        $transformer = new SelectTransformer(null, $quoter);
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CUSTOM_QUOTED', $result);
    }

    public function testContinueNotBreakWhenTableNotInSql(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'notinquery' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
            'users' => [
                'rows' => [['id' => 2]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"users" AS (', $result);
        self::assertStringNotContainsString('"notinquery"', $result);
    }

    public function testColumnsInferredFromRowsWhenColumnsEmpty(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['x' => 10]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('"x"', $result);
        self::assertStringContainsString('10', $result);
    }

    public function testEmptyColumnsWithRowsInfersAndBuildsCte(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('UNION ALL', $result);
        self::assertStringContainsString('"id"', $result);
        self::assertStringContainsString('"name"', $result);
    }

    public function testColumnsInferredFromRowsAppliedToAllRows(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['a' => 1], ['a' => 2, 'b' => 3]],
                'columns' => [],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertSame(2, substr_count($result, '"a"'));
        self::assertSame(2, substr_count($result, '"b"'));
    }

    public function testEmptyCteSelectPartContainsWhereZero(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('SELECT ', $result);
        self::assertStringContainsString(' WHERE 0)', $result);
        self::assertStringContainsString('CAST(NULL AS TEXT) AS "id"', $result);
        self::assertStringContainsString('CAST(NULL AS TEXT) AS "name"', $result);
    }

    public function testExistingWithClauseModifiedWithOnlyOnePrependedCte(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $sql = "WITH existing AS (SELECT 1)\nSELECT * FROM users, existing";
        $result = $transformer->transform($sql, $tables);
        self::assertStringContainsString('"users" AS (', $result);
        $withCount = substr_count($result, 'WITH ');
        self::assertSame(1, $withCount);
        self::assertStringContainsString(",\n", $result);
    }

    public function testEmptyCteOrderTableNameBeforeSelect(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        $pos1 = strpos($result, '"users" AS (SELECT ');
        self::assertNotFalse($pos1);
        $pos2 = strpos($result, 'CAST(NULL AS INTEGER) AS "id"');
        self::assertNotFalse($pos2);
        self::assertGreaterThan($pos1, $pos2);
        self::assertStringContainsString('WHERE 0)', $result);
    }

    public function testSingleRowCteReturnsNonEmptyString(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1, 'name' => 'Alice']],
                'columns' => ['id', 'name'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'), 'name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringStartsWith('WITH "users" AS (SELECT ', $result);
        self::assertStringContainsString("CAST('1' AS INTEGER) AS \"id\"", $result);
        self::assertStringContainsString("CAST('Alice' AS TEXT) AS \"name\"", $result);
        self::assertStringContainsString(")\nSELECT * FROM users", $result);
    }

    public function testExistingWithClauseHasCteBeforeExisting(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $sql = 'WITH existing AS (SELECT 1) SELECT * FROM users, existing';
        $result = $transformer->transform($sql, $tables);
        $withPos = strpos($result, 'WITH ');
        self::assertNotFalse($withPos);
        $usersPos = strpos($result, '"users" AS (');
        self::assertNotFalse($usersPos);
        self::assertGreaterThan($withPos, $usersPos);
        $existingPos = strpos($result, 'existing AS (SELECT 1)');
        self::assertNotFalse($existingPos);
        self::assertGreaterThan($usersPos, $existingPos);
    }

    public function testCustomCastRendererIsUsedForRows(): void
    {
        $castRenderer = static::createStub(CastRenderer::class);
        $castRenderer->method('renderNullCast')->willReturn('CUSTOM_NULL');
        $castRenderer->method('renderCast')->willReturn('CUSTOM_CAST');

        $transformer = new SelectTransformer($castRenderer, null);
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('CUSTOM_CAST', $result);
        self::assertStringNotContainsString('CUSTOM_NULL', $result);
    }

    public function testCustomQuoterIsUsedForRows(): void
    {
        $quoter = static::createStub(IdentifierQuoter::class);
        $quoter->method('quote')->willReturn('[CUSTOM]');

        $transformer = new SelectTransformer(null, $quoter);
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        self::assertStringContainsString('[CUSTOM]', $result);
    }

    public function testExistingWithClauseCteAppearsImmediatelyAfterWith(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $sql = 'WITH existing AS (SELECT 1) SELECT * FROM users, existing';
        $result = $transformer->transform($sql, $tables);
        self::assertStringContainsString('WITH "users" AS (', $result);
        self::assertStringNotContainsString("WITH ,", $result);
    }

    public function testEmptyCteSelectColumnOrderMatchesInput(): void
    {
        $transformer = new SelectTransformer();
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name', 'email'],
                'columnTypes' => [
                    'id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'),
                    'name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
                    'email' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
                ],
            ],
        ];

        $result = $transformer->transform('SELECT * FROM users', $tables);
        $idPos = strpos($result, '"id"');
        $namePos = strpos($result, '"name"');
        $emailPos = strpos($result, '"email"');
        self::assertNotFalse($idPos);
        self::assertNotFalse($namePos);
        self::assertNotFalse($emailPos);
        self::assertLessThan($namePos, $idPos);
        self::assertLessThan($emailPos, $namePos);
        self::assertStringContainsString('WHERE 0)', $result);
    }
}
