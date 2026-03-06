<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Contract\CastRendererContractTest;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;

#[CoversClass(SqliteCastRenderer::class)]
final class SqliteCastRendererTest extends CastRendererContractTest
{
    protected function createRenderer(): CastRenderer
    {
        return new SqliteCastRenderer();
    }

    #[\Override]
    protected function nativeTypeFor(ColumnTypeFamily $family): string
    {
        return match ($family) {
            ColumnTypeFamily::INTEGER => 'INTEGER',
            ColumnTypeFamily::FLOAT => 'REAL',
            ColumnTypeFamily::DOUBLE => 'REAL',
            ColumnTypeFamily::DECIMAL => 'NUMERIC',
            ColumnTypeFamily::STRING => 'TEXT',
            ColumnTypeFamily::TEXT => 'TEXT',
            ColumnTypeFamily::BOOLEAN => 'INTEGER',
            ColumnTypeFamily::DATE => 'TEXT',
            ColumnTypeFamily::TIME => 'TEXT',
            ColumnTypeFamily::DATETIME => 'TEXT',
            ColumnTypeFamily::TIMESTAMP => 'TEXT',
            ColumnTypeFamily::BINARY => 'BLOB',
            ColumnTypeFamily::JSON => 'TEXT',
            ColumnTypeFamily::UNKNOWN => 'TEXT',
        };
    }

    public function testRenderCastInteger(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER');
        $result = $renderer->renderCast('42', $type);
        self::assertSame('CAST(42 AS INTEGER)', $result);
    }

    public function testRenderCastFloat(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::FLOAT, 'FLOAT');
        $result = $renderer->renderCast('3.14', $type);
        self::assertSame('CAST(3.14 AS REAL)', $result);
    }

    public function testRenderCastDouble(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::DOUBLE, 'DOUBLE');
        $result = $renderer->renderCast('3.14', $type);
        self::assertSame('CAST(3.14 AS REAL)', $result);
    }

    public function testRenderCastDecimal(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::DECIMAL, 'DECIMAL(10,2)');
        $result = $renderer->renderCast("'123.45'", $type);
        self::assertSame("CAST('123.45' AS NUMERIC)", $result);
    }

    public function testRenderCastString(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::STRING, 'VARCHAR(255)');
        $result = $renderer->renderCast("'hello'", $type);
        self::assertSame("CAST('hello' AS TEXT)", $result);
    }

    public function testRenderCastText(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::TEXT, 'TEXT');
        $result = $renderer->renderCast("'hello'", $type);
        self::assertSame("CAST('hello' AS TEXT)", $result);
    }

    public function testRenderCastBoolean(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::BOOLEAN, 'BOOLEAN');
        $result = $renderer->renderCast('1', $type);
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastBinary(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::BINARY, 'BLOB');
        $result = $renderer->renderCast("X'DEADBEEF'", $type);
        self::assertSame("CAST(X'DEADBEEF' AS BLOB)", $result);
    }

    public function testRenderCastDate(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::DATE, 'DATE');
        $result = $renderer->renderCast("'2024-01-01'", $type);
        self::assertSame("CAST('2024-01-01' AS TEXT)", $result);
    }

    public function testRenderCastDatetime(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::DATETIME, 'DATETIME');
        $result = $renderer->renderCast("'2024-01-01 12:00:00'", $type);
        self::assertSame("CAST('2024-01-01 12:00:00' AS TEXT)", $result);
    }

    public function testRenderCastTimestamp(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::TIMESTAMP, 'TIMESTAMP');
        $result = $renderer->renderCast("'2024-01-01 12:00:00'", $type);
        self::assertSame("CAST('2024-01-01 12:00:00' AS TEXT)", $result);
    }

    public function testRenderCastTime(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::TIME, 'TIME');
        $result = $renderer->renderCast("'12:00:00'", $type);
        self::assertSame("CAST('12:00:00' AS TEXT)", $result);
    }

    public function testRenderCastJson(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::JSON, 'JSON');
        $result = $renderer->renderCast("'{}'", $type);
        self::assertSame("CAST('{}' AS TEXT)", $result);
    }

    public function testRenderNullCastInteger(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER');
        $result = $renderer->renderNullCast($type);
        self::assertSame('CAST(NULL AS INTEGER)', $result);
    }

    public function testRenderNullCastText(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::STRING, 'VARCHAR(255)');
        $result = $renderer->renderNullCast($type);
        self::assertSame('CAST(NULL AS TEXT)', $result);
    }

    public function testRenderNullCastBoolean(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::BOOLEAN, 'BOOLEAN');
        $result = $renderer->renderNullCast($type);
        self::assertSame('CAST(NULL AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithNativeType(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::UNKNOWN, 'INTEGER');
        $result = $renderer->renderCast('42', $type);
        self::assertSame('CAST(42 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithBlobType(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::UNKNOWN, 'BLOB');
        $result = $renderer->renderNullCast($type);
        self::assertSame('CAST(NULL AS BLOB)', $result);
    }

    public function testRenderCastUnknownFamilyWithUnknownNativeType(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::UNKNOWN, 'CUSTOM_TYPE');
        $result = $renderer->renderNullCast($type);
        self::assertSame('CAST(NULL AS TEXT)', $result);
    }

    public function testRenderCastUnknownFamilyWithIntType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'INT'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithTinyintType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'TINYINT'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithSmallintType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'SMALLINT'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithMediumintType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'MEDIUMINT'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithBigintType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'BIGINT'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithBooleanType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'BOOLEAN'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithBoolType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'BOOL'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithRealType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1.0', new ColumnType(ColumnTypeFamily::UNKNOWN, 'REAL'));
        self::assertSame('CAST(1.0 AS REAL)', $result);
    }

    public function testRenderCastUnknownFamilyWithDoubleType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1.0', new ColumnType(ColumnTypeFamily::UNKNOWN, 'DOUBLE'));
        self::assertSame('CAST(1.0 AS REAL)', $result);
    }

    public function testRenderCastUnknownFamilyWithFloatType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1.0', new ColumnType(ColumnTypeFamily::UNKNOWN, 'FLOAT'));
        self::assertSame('CAST(1.0 AS REAL)', $result);
    }

    public function testRenderCastUnknownFamilyWithDecimalType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1.0', new ColumnType(ColumnTypeFamily::UNKNOWN, 'DECIMAL'));
        self::assertSame('CAST(1.0 AS NUMERIC)', $result);
    }

    public function testRenderCastUnknownFamilyWithNumericType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1.0', new ColumnType(ColumnTypeFamily::UNKNOWN, 'NUMERIC'));
        self::assertSame('CAST(1.0 AS NUMERIC)', $result);
    }

    public function testRenderCastUnknownFamilyWithLowercaseNativeType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1', new ColumnType(ColumnTypeFamily::UNKNOWN, 'integer'));
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testRenderCastUnknownFamilyWithParenthesizedType(): void
    {
        $renderer = new SqliteCastRenderer();
        $result = $renderer->renderCast('1.0', new ColumnType(ColumnTypeFamily::UNKNOWN, 'DECIMAL(10,2)'));
        self::assertSame('CAST(1.0 AS NUMERIC)', $result);
    }

    /**
     * P-CR-5: All ColumnTypeFamily cases are handled.
     */
    public function testAllColumnTypeFamiliesHandled(): void
    {
        $renderer = new SqliteCastRenderer();

        $intResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::INTEGER, 'TEXT'));
        self::assertNotEmpty($intResult);
        self::assertStringContainsString('CAST(', $intResult);

        $floatResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::FLOAT, 'TEXT'));
        self::assertNotEmpty($floatResult);
        self::assertStringContainsString('CAST(', $floatResult);

        $doubleResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::DOUBLE, 'TEXT'));
        self::assertNotEmpty($doubleResult);
        self::assertStringContainsString('CAST(', $doubleResult);

        $decimalResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::DECIMAL, 'TEXT'));
        self::assertNotEmpty($decimalResult);
        self::assertStringContainsString('CAST(', $decimalResult);

        $stringResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::STRING, 'TEXT'));
        self::assertNotEmpty($stringResult);
        self::assertStringContainsString('CAST(', $stringResult);

        $textResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'));
        self::assertNotEmpty($textResult);
        self::assertStringContainsString('CAST(', $textResult);

        $boolResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::BOOLEAN, 'TEXT'));
        self::assertNotEmpty($boolResult);
        self::assertStringContainsString('CAST(', $boolResult);

        $dateResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::DATE, 'TEXT'));
        self::assertNotEmpty($dateResult);
        self::assertStringContainsString('CAST(', $dateResult);

        $timeResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::TIME, 'TEXT'));
        self::assertNotEmpty($timeResult);
        self::assertStringContainsString('CAST(', $timeResult);

        $datetimeResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::DATETIME, 'TEXT'));
        self::assertNotEmpty($datetimeResult);
        self::assertStringContainsString('CAST(', $datetimeResult);

        $timestampResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::TIMESTAMP, 'TEXT'));
        self::assertNotEmpty($timestampResult);
        self::assertStringContainsString('CAST(', $timestampResult);

        $binaryResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::BINARY, 'TEXT'));
        self::assertNotEmpty($binaryResult);
        self::assertStringContainsString('CAST(', $binaryResult);

        $jsonResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::JSON, 'TEXT'));
        self::assertNotEmpty($jsonResult);
        self::assertStringContainsString('CAST(', $jsonResult);

        $unknownResult = $renderer->renderNullCast(new ColumnType(ColumnTypeFamily::UNKNOWN, 'TEXT'));
        self::assertNotEmpty($unknownResult);
        self::assertStringContainsString('CAST(', $unknownResult);
    }

    /**
     * P-CR-4: Determinism.
     */
    public function testDeterminism(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER');
        $result1 = $renderer->renderCast('42', $type);
        $result2 = $renderer->renderCast('42', $type);
        self::assertSame($result1, $result2);
    }

    public function testMapNativeTypeToCastTypeReturnsString(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::UNKNOWN, 'INT(11)');
        $result = $renderer->renderCast('1', $type);
        self::assertSame('CAST(1 AS INTEGER)', $result);
    }

    public function testMapNativeTypeToCastTypeBaseTypeExtracted(): void
    {
        $renderer = new SqliteCastRenderer();
        $type = new ColumnType(ColumnTypeFamily::UNKNOWN, 'VARCHAR(255)');
        $result = $renderer->renderCast("'x'", $type);
        self::assertSame("CAST('x' AS TEXT)", $result);
    }
}
