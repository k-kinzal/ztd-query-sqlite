<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Cte;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteHeaderParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(CteHeaderParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class CteHeaderParserTest extends TestCase
{
    public function testParseHeaderLocatesMainStatementAfterMultipleCtes(): void
    {
        $parser = new CteHeaderParser();
        $sql = 'WITH RECURSIVE "Items"(id) AS NOT MATERIALIZED (SELECT 1), other AS (SELECT 2) SELECT * FROM Items';
        self::assertSame(['names' => ['items', 'other'], 'statementOffset' => 79], $parser->parseHeader($sql));
        self::assertSame(['names' => [], 'statementOffset' => null], $parser->parseHeader('SELECT 1'));
        self::assertSame(['names' => [], 'statementOffset' => null], $parser->parseHeader('WITH t AS (SELECT 1'));
    }

    public function testFindAsIndexSkipsColumnListPunctuation(): void
    {
        $parser = new CteHeaderParser();
        $tokens = [new SqlToken(SqlTokenKind::Symbol, '(', 0, 0, 0), new SqlToken(SqlTokenKind::Symbol, ')', 1, 0, 0), new SqlToken(SqlTokenKind::Word, 'AS', 3, 0, 0)];
        self::assertSame(2, $parser->findAsIndex($tokens, 0));
        self::assertNull($parser->findAsIndex([new SqlToken(SqlTokenKind::Word, 'SELECT', 0, 0, 0)], 0));
        self::assertNull($parser->findAsIndex([], 0));
    }

    public function testIsSymbolRejectsNullAndDifferentKinds(): void
    {
        $parser = new CteHeaderParser();
        self::assertFalse($parser->isSymbol(null, '('));
        self::assertTrue($parser->isSymbol(new SqlToken(SqlTokenKind::Symbol, '(', 0, 0, 0), '('));
        self::assertFalse($parser->isSymbol(new SqlToken(SqlTokenKind::Word, '(', 0, 0, 0), '('));
    }

    public function testIdentifierNameDecodesEscapedQuotedNames(): void
    {
        $parser = new CteHeaderParser();
        self::assertSame('t', $parser->identifierName(new SqlToken(SqlTokenKind::Word, 't', 0, 0, 0)));
        self::assertSame('a"b', $parser->identifierName(new SqlToken(SqlTokenKind::QuotedIdentifier, '"a""b"', 0, 0, 0)));
        self::assertNull($parser->identifierName(new SqlToken(SqlTokenKind::Number, '1', 0, 0, 0)));
    }

    public function testBodyEndIndexHandlesMaterializationAndIncompleteGroups(): void
    {
        $parser = new CteHeaderParser();
        $tokens = SqlTokenStream::tokenize('AS NOT MATERIALIZED () SELECT', SqliteLexerProfile::create())->significantTokens();
        self::assertSame(5, $parser->bodyEndIndex($tokens, 0));
        self::assertNull($parser->bodyEndIndex([], 0));
        self::assertNull($parser->bodyEndIndex(SqlTokenStream::tokenize('AS (', SqliteLexerProfile::create())->significantTokens(), 0));
    }

}
