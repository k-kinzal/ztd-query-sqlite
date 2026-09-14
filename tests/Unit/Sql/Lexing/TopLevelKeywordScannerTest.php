<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Lexing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner;

#[CoversClass(TopLevelKeywordScanner::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\OpaqueSqlSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
final class TopLevelKeywordScannerTest extends TestCase
{
    public function testScanTopLevelKeywordsPreservesOffsetsAndGroupState(): void
    {
        self::assertSame([
            ['keyword' => 'WITH', 'afterGroup' => false, 'offset' => 0],
            ['keyword' => 'T', 'afterGroup' => false, 'offset' => 5],
            ['keyword' => 'AS', 'afterGroup' => false, 'offset' => 7],
            ['keyword' => 'SELECT', 'afterGroup' => true, 'offset' => 21],
            ['keyword' => 'FROM', 'afterGroup' => true, 'offset' => 30],
            ['keyword' => 'T', 'afterGroup' => true, 'offset' => 35],
        ], (new TopLevelKeywordScanner())->scanTopLevelKeywords('WITH t AS (SELECT 1) SELECT * FROM t'));
    }

    public function testScanTopLevelKeywordsSkipsQuotedAndCommentedKeywords(): void
    {
        self::assertSame([], (new TopLevelKeywordScanner())->scanTopLevelKeywords("'SELECT' /* DELETE */ \"UPDATE\" [INSERT] `DROP` -- ALTER"));
        self::assertSame([['keyword' => 'SELECT', 'afterGroup' => false, 'offset' => 2]], (new TopLevelKeywordScanner())->scanTopLevelKeywords(') SELECT 123'));
    }

}
