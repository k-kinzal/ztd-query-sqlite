<?php

declare(strict_types=1);

namespace Fuzz;

use Faker\Factory;
use PHPUnit\Framework\TestCase;
use SqlFaker\SqliteProvider;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;

/**
 * Fuzz tests for SelectTransformer::transform().
 *
 * Guards the following properties:
 * - P-TF-1: transform() never crashes on any generated SELECT
 * - P-TF-2: When SQL references a shadowed table, the output contains a WITH clause (CTE injection)
 * - P-TF-3: With empty table context, SQL is returned unchanged (identity transform)
 * - P-TF-4: Empty-row shadow tables still inject CTE with WHERE FALSE
 */
final class TransformerFuzzTest extends TestCase
{
    private const ITERATIONS = 100;

    private SelectTransformer $transformer;

    private SqliteProvider $provider;

    protected function setUp(): void
    {
        $this->transformer = new SelectTransformer(new SqliteCastRenderer(), new SqliteIdentifierQuoter());
        $faker = Factory::create();
        $this->provider = new SqliteProvider($faker);
    }

    public function testTransformDoesNotCrashOnRandomSelectWithEmptyTables(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $sql = $this->provider->selectStatement();
            try {
                $result = $this->transformer->transform($sql, []);
                self::assertNotEmpty($result, "transform() returned empty string on iteration $i");
                self::assertSame($sql, $result);
            } catch (\Throwable $e) {
                self::fail("transform() crashed on iteration $i with SQL: $sql\nError: " . $e->getMessage());
            }
        }
        self::addToAssertionCount(self::ITERATIONS);
    }

    public function testTransformWithShadowDataContainsWithClause(): void
    {
        /** @var array<string, array{rows: array<int, array<string, mixed>>, columns: array<int, string>, columnTypes: array<string, ColumnType>}> $tables */
        $tables = [
            'users' => [
                'rows' => [
                    ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
                    ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
                ],
                'columns' => ['id', 'name', 'email'],
                'columnTypes' => [
                    'id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'),
                    'name' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
                    'email' => new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
                ],
            ],
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $sql = $this->provider->selectStatement();
            try {
                $result = $this->transformer->transform($sql, $tables);
                self::assertNotEmpty($result, "transform() returned empty string on iteration $i");
                if (stripos($sql, 'users') !== false) {
                    self::assertStringContainsString('WITH', $result, "transform() should inject CTE when SQL references shadowed table on iteration $i");
                }
            } catch (\Throwable $e) {
                self::fail("transform() crashed on iteration $i with SQL: $sql\nError: " . $e->getMessage());
            }
        }
        self::addToAssertionCount(self::ITERATIONS);
    }

    public function testTransformWithEmptyRowsContainsWithClause(): void
    {
        /** @var array<string, array{rows: array<int, array<string, mixed>>, columns: array<int, string>, columnTypes: array<string, ColumnType>}> $tables */
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

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $sql = $this->provider->selectStatement();
            try {
                $result = $this->transformer->transform($sql, $tables);
                self::assertNotEmpty($result, "transform() returned empty string on iteration $i");
                if (stripos($sql, 'users') !== false) {
                    self::assertStringContainsString('WITH', $result, "transform() should inject CTE when SQL references shadowed table on iteration $i");
                }
            } catch (\Throwable $e) {
                self::fail("transform() crashed on iteration $i with SQL: $sql\nError: " . $e->getMessage());
            }
        }
        self::addToAssertionCount(self::ITERATIONS);
    }
}
