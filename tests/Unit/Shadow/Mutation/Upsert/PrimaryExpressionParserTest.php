<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Upsert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionCursor;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\PrimaryExpressionParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Shadow\Mutation\UpsertColumnSource;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(PrimaryExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ArithmeticExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ComparisonExpressionParser::class)]
#[UsesClass(ExpressionCursor::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionTokenDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\LogicalExpressionParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class PrimaryExpressionParserTest extends TestCase
{
    public function testParsePrimaryEvaluatesParenthesizedExpression(): void
    {
        $sql = '(count + EXCLUDED.count)';
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new PrimaryExpressionParser($cursor))->parsePrimary();
        self::assertSame(7, $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

    public function testParsePrimaryDecodesStringLiteral(): void
    {
        $sql = "'a''b'";
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new PrimaryExpressionParser($cursor))->parsePrimary();
        self::assertSame("a'b", $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

    public function testParsePrimaryPreservesNull(): void
    {
        $sql = 'NULL';
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new PrimaryExpressionParser($cursor))->parsePrimary();
        self::assertSame(null, $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

    public function testParseColumnSelectsIncomingRow(): void
    {
        $sql = 'EXCLUDED.count';
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new PrimaryExpressionParser($cursor))->parseColumn();
        self::assertSame(3, $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

    public function testColumnSourceDistinguishesTableAndExcluded(): void
    {
        $parser = new PrimaryExpressionParser(new ExpressionCursor('', 'users', []));
        self::assertSame(UpsertColumnSource::Incoming, $parser->columnSource('excluded'));
        self::assertSame(UpsertColumnSource::Existing, $parser->columnSource('USERS'));
    }

    public function testColumnSourceRejectsUnrelatedQualifiers(): void
    {
        $this->expectException(\ZtdQuery\Exception\UnsupportedSqlException::class);
        (new PrimaryExpressionParser(new ExpressionCursor('other.count', 'users', [])))->columnSource('other');
    }

}
