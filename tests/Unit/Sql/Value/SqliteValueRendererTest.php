<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\ColumnTypeFamily;

#[CoversClass(SqliteValueRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\CastTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueExpressionRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueLiteralRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer::class)]
final class SqliteValueRendererTest extends TestCase
{
    public function testRenderValueIntegerStringUsesNumericExpression(): void
    {
        $renderer = new SqliteValueRenderer();

        self::assertSame(
            "CAST('42' AS INTEGER)",
            $renderer->renderValue('42', new ColumnDeclaration(ColumnTypeFamily::INTEGER, 'INTEGER')),
        );
    }

    public function testFloatUsesRoundTripRepresentation(): void
    {
        $renderer = new SqliteValueRenderer();

        self::assertSame(
            "CAST('2.718281828459045' AS REAL)",
            $renderer->renderValue(2.718281828459045, new ColumnDeclaration(ColumnTypeFamily::FLOAT, 'REAL')),
        );
    }

    public function testBinaryUsesLosslessHexLiteral(): void
    {
        $renderer = new SqliteValueRenderer();

        self::assertSame(
            "CAST(X'0001ff' AS BLOB)",
            $renderer->renderValue("\x00\x01\xFF", new ColumnDeclaration(ColumnTypeFamily::BINARY, 'BLOB')),
        );
    }

    public function testNullRetainsDeclaredType(): void
    {
        $renderer = new SqliteValueRenderer();

        self::assertSame('NULL', $renderer->renderValue(null, new ColumnDeclaration(ColumnTypeFamily::TEXT, 'TEXT')));
    }

    public function testTextRemainsText(): void
    {
        $renderer = new SqliteValueRenderer();

        self::assertSame("CAST('O''Reilly' AS TEXT)", $renderer->renderValue("O'Reilly"));
    }

    public function testInferredScalarTypesUseNativeLiterals(): void
    {
        $renderer = new SqliteValueRenderer();

        self::assertSame('1', $renderer->renderValue(true));
        self::assertSame('2.718281828459045', $renderer->renderValue(2.718281828459045));
        self::assertSame('CAST(42 AS INTEGER)', $renderer->renderValue(42));
    }

    public function testDeclaredBooleanAndIntegerUseTypedText(): void
    {
        $renderer = new SqliteValueRenderer();

        self::assertSame(
            "CAST('1' AS INTEGER)",
            $renderer->renderValue(true, new ColumnDeclaration(ColumnTypeFamily::BOOLEAN, 'BOOLEAN')),
        );
        self::assertSame(
            "CAST('0' AS INTEGER)",
            $renderer->renderValue(false, new ColumnDeclaration(ColumnTypeFamily::BOOLEAN, 'BOOLEAN')),
        );
        self::assertSame(
            "CAST('42' AS INTEGER)",
            $renderer->renderValue(42, new ColumnDeclaration(ColumnTypeFamily::INTEGER, 'INTEGER')),
        );
    }

    public function testInferredAndDeclaredStringableRemainDistinct(): void
    {
        $value = new class () implements Stringable {
            public function __toString(): string
            {
                return 'CURRENT_TIMESTAMP';
            }
        };
        $renderer = new SqliteValueRenderer();

        self::assertSame('CURRENT_TIMESTAMP', $renderer->renderValue($value));
        self::assertSame(
            "CAST('CURRENT_TIMESTAMP' AS TEXT)",
            $renderer->renderValue($value, new ColumnDeclaration(ColumnTypeFamily::TEXT, 'TEXT')),
        );
    }

    public function testUntypedArrayIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        (new SqliteValueRenderer())->renderValue(['value']);
    }

    public function testDeclaredNonScalarUsesSerializedRepresentation(): void
    {
        self::assertSame(
            "CAST('a:1:{i:0;s:5:\"value\";}' AS TEXT)",
            (new SqliteValueRenderer())->renderValue(['value'], new ColumnDeclaration(ColumnTypeFamily::JSON, 'JSON')),
        );
    }

    public function testBinaryStreamIsReadWithoutChangingItsPosition(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x00\x01\xFF");
        fseek($stream, 1);

        self::assertSame(
            "CAST(X'0001ff' AS BLOB)",
            (new SqliteValueRenderer())->renderValue($stream, new ColumnDeclaration(ColumnTypeFamily::BINARY, 'BLOB')),
        );
        self::assertSame(1, ftell($stream));
        fclose($stream);
    }

    public function testBinaryRejectsNonStringableNonResource(): void
    {
        $this->expectException(RuntimeException::class);

        (new SqliteValueRenderer())->renderValue(
            ['value'],
            new ColumnDeclaration(ColumnTypeFamily::BINARY, 'BLOB'),
        );
    }
}
