<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Dml\Expression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\ValueListParser;

#[CoversClass(ValueListParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
final class ValueListParserTest extends TestCase
{
    public function testParseValueSetsPreservesExpressions(): void
    {
        self::assertSame([['1', "'a,b'", 'coalesce(2, 3)'], ['4', "'x''y'", 'NULL']], (new ValueListParser())->parseValueSets("(1, 'a,b', coalesce(2, 3)), (4, 'x''y', NULL) RETURNING id"));
    }

    public function testParseValueSetsHandlesEmptyAndIncompleteRows(): void
    {
        $parser = new ValueListParser();
        self::assertSame([], $parser->parseValueSets(''));
        self::assertSame([], $parser->parseValueSets('()'));
        self::assertSame([], $parser->parseValueSets('1, 2'));
        self::assertSame([['1']], $parser->parseValueSets('(1, incomplete'));
    }

}
