<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(SqliteLexerProfile::class)]
final class SqliteLexerProfileTest extends TestCase
{
    public function testSelectsSqliteLexicalCapabilities(): void
    {
        $stream = SqlTokenStream::tokenize(
            'SELECT [bracket]]name], `grave`, "quoted", ?12, :name, @name, $namespace::value(suffix), 0xCA_FE',
            SqliteLexerProfile::create(),
        );
        $tokens = $stream->significantTokens();
        $lastToken = $tokens[count($tokens) - 1] ?? null;

        self::assertSame(['name' => 'bracket]name', 'next' => 2], $stream->identifierAt(1));
        self::assertSame(
            ['?12', ':name', '@name', '$namespace::value(suffix)'],
            array_values(array_map(
                static fn (SqlToken $token): string => $token->text,
                array_filter($tokens, static fn (SqlToken $token): bool => $token->kind === SqlTokenKind::Parameter),
            )),
        );
        self::assertNotNull($lastToken);
        self::assertSame(SqlTokenKind::Number, $lastToken->kind);
        self::assertSame('0xCA_FE', $lastToken->text);
        self::assertTrue(SqliteLexerProfile::create()->startsLineComment('-- comment', 0));
        self::assertSame(['/*', '*/'], SqliteLexerProfile::create()->blockCommentAt('/* comment */', 0));
        self::assertSame("'", SqliteLexerProfile::create()->stringQuoteClosing("'"));
        self::assertSame('"', SqliteLexerProfile::create()->identifierQuoteClosing('"'));
        self::assertNull(SqliteLexerProfile::create()->namedParameterPrefixAt('value::type', 6));
        self::assertTrue(SqliteLexerProfile::create()->isBracketOpening('['));
        self::assertTrue(SqliteLexerProfile::create()->isBracketClosing(']'));
        self::assertTrue(SqliteLexerProfile::create()->isNestingOpening('('));
        self::assertTrue(SqliteLexerProfile::create()->isNestingClosing(')'));
        self::assertFalse(SqliteLexerProfile::create()->supportsNestedBlockComments());
        self::assertSame(0, SqliteLexerProfile::create()->positionalParameterLengthAt('$1', 0));
        self::assertFalse(SqliteLexerProfile::create()->stringUsesBackslashEscapes("'value'", 0));
    }
}
