<?php

declare(strict_types=1);

namespace Tests\Unit\Schema\Key;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyTokens;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(ForeignKeyTokens::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class ForeignKeyTokensTest extends TestCase
{
    public function testTableBodyIgnoresNestedParentheses(): void
    {
        $parser = new ForeignKeyTokens();
        self::assertSame('id INTEGER, CHECK(id IN (1, 2))', $parser->tableBody('CREATE TABLE t(id INTEGER, CHECK(id IN (1, 2)))'));
        self::assertNull($parser->tableBody('SELECT (1)'));
        self::assertNull($parser->tableBody('CREATE TABLE t(id INTEGER'));
    }

    public function testKeywordIndexRequiresTopLevelKeyword(): void
    {
        $tokens = SqlTokenStream::tokenize('(REFERENCES) REFERENCES parent', SqliteLexerProfile::create())->significantTokens();
        self::assertSame(3, ForeignKeyTokens::keywordIndex($tokens, 'REFERENCES'));
        self::assertNull(ForeignKeyTokens::keywordIndex($tokens, 'FOREIGN'));
    }

    public function testSymbolIndexHonorsOffsetAndNesting(): void
    {
        $tokens = SqlTokenStream::tokenize('(a, b), c', SqliteLexerProfile::create())->significantTokens();
        self::assertSame(5, ForeignKeyTokens::symbolIndex($tokens, ',', 0));
        self::assertNull(ForeignKeyTokens::symbolIndex($tokens, ',', 6));
    }

    public function testIsSymbolDistinguishesPunctuation(): void
    {
        self::assertFalse(ForeignKeyTokens::isSymbol(null, '('));
        self::assertTrue(ForeignKeyTokens::isSymbol(new SqlToken(SqlTokenKind::Symbol, '(', 0, 0, 0), '('));
        self::assertFalse(ForeignKeyTokens::isSymbol(new SqlToken(SqlTokenKind::Word, '(', 0, 0, 0), '('));
    }

}
