<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Lexing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder;

#[CoversClass(IdentifierDecoder::class)]
final class IdentifierDecoderTest extends TestCase
{
    public function testUnquoteIdentifierUnquoteDoubleQuoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('users', $parser->unquoteIdentifier('"users"'));
    }

    public function testUnquoteIdentifierUnquoteBacktickQuoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('users', $parser->unquoteIdentifier('`users`'));
    }

    public function testUnquoteIdentifierUnquoteBracketQuoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('users', $parser->unquoteIdentifier('[users]'));
    }

    public function testUnquoteIdentifierUnquoteUnquoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('users', $parser->unquoteIdentifier('users'));
    }

    public function testUnquoteIdentifierUnquoteEscapedDoubleQuotes(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('col"name', $parser->unquoteIdentifier('"col""name"'));
    }

    public function testUnquoteIdentifierWithLeadingWhitespace(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('users', $parser->unquoteIdentifier('  users  '));
    }

    public function testUnquoteIdentifierEscapedBackticks(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('col`name', $parser->unquoteIdentifier('`col``name`'));
    }

    public function testUnquoteIdentifierSingleCharDoubleQuote(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('"', $parser->unquoteIdentifier('"'));
    }

    public function testUnquoteIdentifierSingleCharBacktick(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('`', $parser->unquoteIdentifier('`'));
    }

    public function testUnquoteIdentifierSingleCharBracket(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('[', $parser->unquoteIdentifier('['));
    }

    public function testUnquoteIdentifierEmptyDoubleQuoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('', $parser->unquoteIdentifier('""'));
    }

    public function testUnquoteIdentifierEmptyBacktickQuoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('', $parser->unquoteIdentifier('``'));
    }

    public function testUnquoteIdentifierEmptyBracketQuoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('', $parser->unquoteIdentifier('[]'));
    }

    public function testUnquoteIdentifierDoubleQuoteWithEscapes(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('a"b', $parser->unquoteIdentifier('"a""b"'));
    }

    public function testUnquoteIdentifierBacktickWithEscapes(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('a`b', $parser->unquoteIdentifier('`a``b`'));
    }

    public function testUnquoteIdentifierBracketContent(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('my col', $parser->unquoteIdentifier('[my col]'));
    }

    public function testUnquoteIdentifierUnquotedReturnsAsIs(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('users', $parser->unquoteIdentifier('users'));
    }

    public function testUnquoteIdentifierWithWhitespace(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('users', $parser->unquoteIdentifier('  users  '));
    }

    public function testUnquoteIdentifierMismatchedQuotes(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('"abc', $parser->unquoteIdentifier('"abc'));
    }

    public function testUnquoteIdentifierDoubleQuote(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('table', $parser->unquoteIdentifier('"table"'));
    }

    public function testUnquoteIdentifierBacktick(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('table', $parser->unquoteIdentifier('`table`'));
    }

    public function testUnquoteIdentifierBracket(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('table', $parser->unquoteIdentifier('[table]'));
    }

    public function testUnquoteIdentifierUnquoted(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('table', $parser->unquoteIdentifier('table'));
    }

    public function testUnquoteIdentifierSingleChar(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('x', $parser->unquoteIdentifier('x'));
    }

    public function testUnquoteIdentifierEmpty(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('', $parser->unquoteIdentifier(''));
    }

    public function testUnquoteIdentifierEscapedDoubleQuote(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('my"table', $parser->unquoteIdentifier('"my""table"'));
    }

    public function testUnquoteIdentifierEscapedBacktick(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('my`table', $parser->unquoteIdentifier('`my``table`'));
    }

    public function testUnquoteIdentifierOnlyOpenDoubleQuote(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('"x', $parser->unquoteIdentifier('"x'));
    }

    public function testUnquoteIdentifierOnlyOpenBacktick(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('`x', $parser->unquoteIdentifier('`x'));
    }

    public function testUnquoteIdentifierOnlyOpenBracket(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame('[x', $parser->unquoteIdentifier('[x'));
    }

    public function testIdentifierEndIndexAdvancesOneWholeIdentifier(): void
    {
        $parser = new IdentifierDecoder();
        self::assertSame(1, $parser->identifierEndIndex([], 0));
        self::assertSame(1, $parser->identifierEndIndex([new \ZtdQuery\Sql\SqlToken(\ZtdQuery\Sql\SqlTokenKind::Word, 'users', 0, 0, 0)], 0));
    }

    public function testParseColumnListUnquotesAndOmitsEmptyItems(): void
    {
        self::assertSame(['id', 'full name', 'age'], (new IdentifierDecoder())->parseColumnList('id, "full name",, `age`'));
    }

}
