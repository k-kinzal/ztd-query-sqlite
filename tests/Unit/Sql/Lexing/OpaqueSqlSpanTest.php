<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Lexing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Lexing\OpaqueSqlSpan;

#[CoversClass(OpaqueSqlSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
final class OpaqueSqlSpanTest extends TestCase
{
    public function testLengthDistinguishesSyntaxAndOpaqueSpans(): void
    {
        $span = new OpaqueSqlSpan();
        self::assertNull($span->length('SELECT'));
        self::assertNull($span->length(''));
        self::assertSame(5, $span->length("-- x\nSELECT"));
        self::assertSame(3, $span->length("#x\nSELECT"));
        self::assertSame(7, $span->length('/* x */SELECT'));
        self::assertSame(4, $span->length('/* x'));
        self::assertSame(6, $span->length("'a''b' + 1"));
        self::assertSame(3, $span->length('"a" + 1'));
        self::assertSame(3, $span->length('`a` + 1'));
        self::assertSame(3, $span->length('[a] + 1'));
        self::assertSame(2, $span->length('[a'));
    }

}
