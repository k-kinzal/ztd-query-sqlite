<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Returning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Returning\ReturningItemParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(ReturningItemParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class ReturningItemParserTest extends TestCase
{
    public function testParseItemKeepsColumnAndAliasSeparate(): void
    {
        $parser = new ReturningItemParser();
        self::assertSame(['source' => 'name', 'output' => 'label'], $parser->parseItem('users.name AS "label"'));
        self::assertSame(['source' => null, 'output' => null], $parser->parseItem('users.*'));
        self::assertNull($parser->parseItem('users.* AS label'));
        self::assertNull($parser->parseItem('upper(name)'));
        self::assertNull($parser->parseItem('name AS label extra'));
    }

    public function testAsIndexLocatesExplicitAlias(): void
    {
        $parser = new ReturningItemParser();
        self::assertSame(1, $parser->asIndex(SqlTokenStream::tokenize('name AS label', SqliteLexerProfile::create())->significantTokens()));
        self::assertNull($parser->asIndex(SqlTokenStream::tokenize('name', SqliteLexerProfile::create())->significantTokens()));
    }

    public function testIsIdentifierPathRejectsOperators(): void
    {
        $parser = new ReturningItemParser();
        self::assertTrue($parser->isIdentifierPath(SqlTokenStream::tokenize('main.users.name', SqliteLexerProfile::create())->significantTokens()));
        self::assertTrue($parser->isIdentifierPath(SqlTokenStream::tokenize('users.*', SqliteLexerProfile::create())->significantTokens()));
        self::assertFalse($parser->isIdentifierPath(SqlTokenStream::tokenize('name + 1', SqliteLexerProfile::create())->significantTokens()));
        self::assertFalse($parser->isIdentifierPath(SqlTokenStream::tokenize('1', SqliteLexerProfile::create())->significantTokens()));
    }

    public function testIdentifierNameUnescapesSupportedQuotes(): void
    {
        $parser = new ReturningItemParser();
        self::assertSame('name', $parser->identifierName(new SqlToken(SqlTokenKind::Word, 'name', 0, 0, 0)));
        self::assertSame('a"b', $parser->identifierName(new SqlToken(SqlTokenKind::QuotedIdentifier, '"a""b"', 0, 0, 0)));
        self::assertNull($parser->identifierName(new SqlToken(SqlTokenKind::Number, '1', 0, 0, 0)));
        self::assertNull($parser->identifierName(new SqlToken(SqlTokenKind::QuotedIdentifier, '""', 0, 0, 0)));
    }

}
