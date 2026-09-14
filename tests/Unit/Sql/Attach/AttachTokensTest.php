<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Attach;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Attach\AttachTokens;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(AttachTokens::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class AttachTokensTest extends TestCase
{
    public function testIsIdentifierSuffixRequiresExactlyOneIdentifier(): void
    {
        self::assertTrue(AttachTokens::isIdentifierSuffix(SqlTokenStream::tokenize('db', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertTrue(AttachTokens::isIdentifierSuffix(SqlTokenStream::tokenize('"db name"', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertFalse(AttachTokens::isIdentifierSuffix(SqlTokenStream::tokenize('db extra', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertFalse(AttachTokens::isIdentifierSuffix(SqlTokenStream::tokenize('123', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertFalse(AttachTokens::isIdentifierSuffix(SqlTokenStream::tokenize('[db name]', SqliteLexerProfile::create())->significantTokens(), 0));
    }

    public function testIsSymbolChecksKindAndSpelling(): void
    {
        self::assertTrue(AttachTokens::isSymbol(new SqlToken(SqlTokenKind::Symbol, ';', 0, 0, 0), ';'));
        self::assertFalse(AttachTokens::isSymbol(new SqlToken(SqlTokenKind::Word, ';', 0, 0, 0), ';'));
        self::assertFalse(AttachTokens::isSymbol(new SqlToken(SqlTokenKind::Symbol, ',', 0, 0, 0), ';'));
    }

}
