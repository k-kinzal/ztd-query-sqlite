<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Transaction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Platform\Sqlite\Sql\Transaction\TransactionTokens;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(TransactionTokens::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class TransactionTokensTest extends TestCase
{
    public function testMatchesAnySelectsOneCompleteForm(): void
    {
        $tokens = SqlTokenStream::tokenize('BEGIN IMMEDIATE', SqliteLexerProfile::create())->significantTokens();
        self::assertTrue((new TransactionTokens())->matchesAny($tokens, [['BEGIN'], ['BEGIN', 'IMMEDIATE']]));
        self::assertFalse((new TransactionTokens())->matchesAny($tokens, [['BEGIN']]));
    }

    public function testMatchesRequiresEveryKeywordAndToken(): void
    {
        $matcher = new TransactionTokens();
        $tokens = SqlTokenStream::tokenize('begin immediate', SqliteLexerProfile::create())->significantTokens();
        self::assertTrue($matcher->matches($tokens, ['BEGIN', 'IMMEDIATE']));
        self::assertFalse($matcher->matches($tokens, ['BEGIN', 'EXCLUSIVE']));
        self::assertFalse($matcher->matches($tokens, ['BEGIN']));
    }

    public function testNameAfterDecodesOnlyACompleteSavepointForm(): void
    {
        $matcher = new TransactionTokens();
        self::assertSame('point "one', $matcher->nameAfter(SqlTokenStream::tokenize('SAVEPOINT "point ""one"', SqliteLexerProfile::create())->significantTokens(), [['SAVEPOINT']]));
        self::assertNull($matcher->nameAfter(SqlTokenStream::tokenize('SAVEPOINT 1', SqliteLexerProfile::create())->significantTokens(), [['SAVEPOINT']]));
        self::assertNull($matcher->nameAfter(SqlTokenStream::tokenize('SAVEPOINT a extra', SqliteLexerProfile::create())->significantTokens(), [['SAVEPOINT']]));
    }

    public function testUnquotePreservesPlainNamesAndRejectsUnclosedQuotes(): void
    {
        $matcher = new TransactionTokens();
        self::assertSame('point', $matcher->unquote('point'));
        self::assertSame('a`b', $matcher->unquote('`a``b`'));
        self::assertNull($matcher->unquote('"point'));
        self::assertSame('', $matcher->unquote(''));
    }

}
