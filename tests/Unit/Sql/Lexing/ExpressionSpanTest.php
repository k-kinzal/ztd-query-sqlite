<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Lexing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan;

#[CoversClass(ExpressionSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
final class ExpressionSpanTest extends TestCase
{
    public function testEndSkipsNestedAndQuotedDelimiters(): void
    {
        $scanner = new ExpressionSpan();
        self::assertSame(11, $scanner->end('coalesce(1), next', 0));
        self::assertSame(7, $scanner->end("'a,''b',next", 0));
        self::assertSame(3, $scanner->end('abc)tail', 0));
        self::assertSame(8, $scanner->end('abc)tail', 0, false));
        self::assertSame(4, $scanner->end('xxxx', 4));
    }

}
