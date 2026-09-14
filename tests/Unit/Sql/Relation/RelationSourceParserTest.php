<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Relation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Relation\RelationSourceParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(RelationSourceParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class RelationSourceParserTest extends TestCase
{
    public function testReferencesFromClauseFindsJoinsButNotSubqueryBodies(): void
    {
        $parser = new RelationSourceParser();
        self::assertSame(['users', 'orders'], array_column($parser->referencesFromClause('main.users u JOIN orders o ON o.user_id = u.id'), 'name'));
        self::assertSame(['users', 'orders'], array_column($parser->referencesFromClause('(users JOIN orders ON users.id = orders.user_id)'), 'name'));
        self::assertSame([], $parser->referencesFromClause('(SELECT * FROM hidden)'));
        self::assertSame([], $parser->referencesFromClause('json_each(items)'));
        self::assertSame([], $parser->referencesFromClause('()'));
    }

    public function testNestedReferencesTranslatesOffsets(): void
    {
        $parser = new RelationSourceParser();
        self::assertSame([['name' => 'users', 'start' => 3, 'unqualifiedStart' => 3, 'end' => 8]], $parser->nestedReferences('xx(users)', 3, 8));
        self::assertSame([], $parser->nestedReferences('(SELECT 1)', 1, 9));
    }

    public function testClosingTokenReturnsMatchingTopLevelEnd(): void
    {
        $parser = new RelationSourceParser();
        $tokens = SqlTokenStream::tokenize('(users)', SqliteLexerProfile::create())->significantTokens();
        self::assertSame($tokens[2], $parser->closingToken($tokens, 0));
        self::assertNull($parser->closingToken([], 0));
    }

    public function testReferenceAtRetainsQualifiedOffsets(): void
    {
        $parser = new RelationSourceParser();
        $sql = 'main.users';
        self::assertSame(['name' => 'users', 'start' => 0, 'unqualifiedStart' => 5, 'end' => 10], $parser->referenceAt($sql, SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertNull($parser->referenceAt('SELECT', SqlTokenStream::tokenize('SELECT', SqliteLexerProfile::create())->significantTokens(), 0));
    }

    public function testIdentifierComponentAtAcceptsWordsAndQuotedNames(): void
    {
        $parser = new RelationSourceParser();
        self::assertSame(['full name', 1, 0, 0, 11], $parser->identifierComponentAt(SqlTokenStream::tokenize('"full name"', SqliteLexerProfile::create())->significantTokens(), 0));
        self::assertNull($parser->identifierComponentAt([], 0));
        self::assertNull($parser->identifierComponentAt(SqlTokenStream::tokenize('123', SqliteLexerProfile::create())->significantTokens(), 0));
    }

    public function testFindFromEndStopsAtClauseBoundary(): void
    {
        $parser = new RelationSourceParser();
        $sql = 'SELECT * FROM users ORDER BY id';
        $tokens = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens();
        self::assertSame(20, $parser->findFromEnd($sql, $tokens, $tokens[2]));
        self::assertSame(19, $parser->findFromEnd('SELECT * FROM users', array_slice($tokens, 0, 4), $tokens[2]));
    }

    public function testMatchesKeywordSequenceChecksEveryPart(): void
    {
        $parser = new RelationSourceParser();
        $tokens = SqlTokenStream::tokenize('ORDER BY id', SqliteLexerProfile::create())->significantTokens();
        self::assertTrue($parser->matchesKeywordSequence($tokens, 0, ['ORDER', 'BY']));
        self::assertFalse($parser->matchesKeywordSequence($tokens, 0, ['ORDER', 'FROM']));
        self::assertFalse($parser->matchesKeywordSequence([], 0, ['ORDER']));
    }

    public function testTokensOmitsCommentsAndWhitespace(): void
    {
        self::assertSame(['SELECT', '1'], array_map(static fn (SqlToken $token): string => $token->text, (new RelationSourceParser())->tokens(' SELECT /* x */ 1 ')));
    }

}
