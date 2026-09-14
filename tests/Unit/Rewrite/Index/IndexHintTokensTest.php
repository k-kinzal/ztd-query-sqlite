<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Index\IndexHintTokens;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(IndexHintTokens::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class IndexHintTokensTest extends TestCase
{
    public function testHintRangeRecognizesBothSupportedForms(): void
    {
        self::assertSame(['start' => 0, 'end' => 15], IndexHintTokens::hintRange(SqlTokenStream::tokenize('INDEXED BY idx1', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertSame(['start' => 0, 'end' => 11], IndexHintTokens::hintRange(SqlTokenStream::tokenize('NOT INDEXED', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertNull(IndexHintTokens::hintRange(SqlTokenStream::tokenize('INDEXED BY', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertNull(IndexHintTokens::hintRange(SqlTokenStream::tokenize('NOT NULL', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertNull(IndexHintTokens::hintRange([], 0));
    }

    public function testTokenIndexAtOrAfterUsesByteOffsets(): void
    {
        $tokens = SqlTokenStream::tokenize('users AS u', SqliteLexerProfile::create())->significantTokens();
        self::assertSame(1, IndexHintTokens::tokenIndexAtOrAfter($tokens, 5));
        self::assertSame(2, IndexHintTokens::tokenIndexAtOrAfter($tokens, 9));
        self::assertSame(3, IndexHintTokens::tokenIndexAtOrAfter($tokens, 10));
    }

    public function testSkipAliasLeavesSourceClausesUntouched(): void
    {
        self::assertSame(2, IndexHintTokens::skipAlias(SqlTokenStream::tokenize('AS u INDEXED BY idx', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertSame(1, IndexHintTokens::skipAlias(SqlTokenStream::tokenize('u INDEXED BY idx', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertSame(0, IndexHintTokens::skipAlias(SqlTokenStream::tokenize('WHERE id = 1', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertSame(0, IndexHintTokens::skipAlias([], 0));
    }

    public function testIsSourceBoundaryRecognizesClauseKeywords(): void
    {
        self::assertTrue(IndexHintTokens::isSourceBoundary(new SqlToken(SqlTokenKind::Word, 'JOIN', 0, 0, 0)));
        self::assertTrue(IndexHintTokens::isSourceBoundary(new SqlToken(SqlTokenKind::Word, 'INDEXED', 0, 0, 0)));
        self::assertFalse(IndexHintTokens::isSourceBoundary(new SqlToken(SqlTokenKind::QuotedIdentifier, '"JOIN"', 0, 0, 0)));
        self::assertFalse(IndexHintTokens::isSourceBoundary(new SqlToken(SqlTokenKind::Word, 'alias', 0, 0, 0)));
    }

    public function testIdentifierEndIndexRequiresACompleteName(): void
    {
        self::assertSame(1, IndexHintTokens::identifierEndIndex(SqlTokenStream::tokenize('"index name"', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertNull(IndexHintTokens::identifierEndIndex(SqlTokenStream::tokenize('123', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertNull(IndexHintTokens::identifierEndIndex([], 0));
    }

}
