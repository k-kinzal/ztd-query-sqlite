<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Value\ValueLiteralRenderer;

#[CoversClass(ValueLiteralRenderer::class)]
final class ValueLiteralRendererTest extends TestCase
{
    public function testQuoteValueEscapesApostrophesWithoutInterpretingBytes(): void
    {
        $renderer = new ValueLiteralRenderer();
        self::assertSame("'O''Brien'", $renderer->quoteValue("O'Brien"));
        self::assertSame("''", $renderer->quoteValue(''));
        self::assertSame("'a\0b'", $renderer->quoteValue("a\0b"));
    }

    public function testReadStreamPreservesTheCallerPosition(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "a\0b");
        fseek($stream, 1);
        self::assertSame("a\0b", (new ValueLiteralRenderer())->readStream($stream));
        self::assertSame(1, ftell($stream));
        fclose($stream);
    }

}
