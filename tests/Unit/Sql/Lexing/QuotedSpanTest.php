<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Lexing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan;

#[CoversClass(QuotedSpan::class)]
final class QuotedSpanTest extends TestCase
{
    public function testQuotedLengthStopsAfterClosingDelimiter(): void
    {
        self::assertSame(6, QuotedSpan::quotedLength("'a''b' tail", "'"));
        self::assertSame(3, QuotedSpan::quotedLength('"a" tail', '"'));
        self::assertSame(4, QuotedSpan::quotedLength("'abc", "'"));
        self::assertSame(4, QuotedSpan::quotedLength(str_repeat("'", 4) . ' tail', "'"));
        self::assertSame(5, QuotedSpan::quotedLength("'a''b", "'"));
    }

    public function testBracketQuotedLengthLocatesClosingBracket(): void
    {
        self::assertSame(4, QuotedSpan::bracketQuotedLength('[abc] tail'));
        self::assertSame(4, QuotedSpan::bracketQuotedLength('[abc'));
    }

}
