<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Upsert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionTokenDecoder;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;

#[CoversClass(ExpressionTokenDecoder::class)]
final class ExpressionTokenDecoderTest extends TestCase
{
    public function testIsSymbolRequiresSymbolToken(): void
    {
        $decoder = new ExpressionTokenDecoder('');
        self::assertTrue($decoder->isSymbol(new SqlToken(SqlTokenKind::Symbol, '+', 0, 0, 0), ['+', '-']));
        self::assertFalse($decoder->isSymbol(new SqlToken(SqlTokenKind::Word, '+', 0, 0, 0), ['+']));
        self::assertFalse($decoder->isSymbol(new SqlToken(SqlTokenKind::Symbol, '*', 0, 0, 0), ['+']));
    }

    public function testIsIdentifierRecognizesOnlyNames(): void
    {
        $decoder = new ExpressionTokenDecoder('');
        self::assertTrue($decoder->isIdentifier(new SqlToken(SqlTokenKind::Word, 'name', 0, 0, 0)));
        self::assertTrue($decoder->isIdentifier(new SqlToken(SqlTokenKind::QuotedIdentifier, '"name"', 0, 0, 0)));
        self::assertFalse($decoder->isIdentifier(new SqlToken(SqlTokenKind::Number, '1', 0, 0, 0)));
    }

    public function testIdentifierDecodesEscapedQuote(): void
    {
        $decoder = new ExpressionTokenDecoder('');
        self::assertSame('name', $decoder->identifier(new SqlToken(SqlTokenKind::Word, 'name', 0, 0, 0)));
        self::assertSame('a"b', $decoder->identifier(new SqlToken(SqlTokenKind::QuotedIdentifier, '"a""b"', 0, 0, 0)));
    }

    public function testNumberPreservesNumericFamilies(): void
    {
        $decoder = new ExpressionTokenDecoder('');
        self::assertSame(1024, $decoder->number('1_024'));
        self::assertSame(1.25, $decoder->number('1.25'));
        self::assertSame(100.0, $decoder->number('1e2'));
    }

    public function testStringDecodesSqlEscapes(): void
    {
        self::assertSame("a'b", (new ExpressionTokenDecoder(''))->string("'a''b'"));
    }

    public function testUnsupportedRetainsOriginalSql(): void
    {
        $error = (new ExpressionTokenDecoder('count || name'))->unsupported();
        self::assertSame('count || name', $error->getSql());
        self::assertStringContainsString('Unsupported UPSERT expression', $error->getMessage());
    }

}
