<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Dml\Expression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\AssignmentColumnParser;

#[CoversClass(AssignmentColumnParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder::class)]
final class AssignmentColumnParserTest extends TestCase
{
    public function testParseReturnsTargetAndExpressionBoundary(): void
    {
        $parser = new AssignmentColumnParser();
        self::assertSame(['name' => 'name', 'end' => 4], $parser->parse('name = 1', 0));
        self::assertSame(['name' => 'full name', 'end' => 11], $parser->parse('"full name" = 1', 0));
        self::assertSame(['name' => 'name', 'end' => 6], $parser->parse('t.name=1', 0));
        self::assertSame(['name' => 'name', 'end' => 8], $parser->parse('  [name]=1', 2));
    }

}
