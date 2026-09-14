<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Upsert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ArithmeticExpressionParser;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionCursor;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(ArithmeticExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ComparisonExpressionParser::class)]
#[UsesClass(ExpressionCursor::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionTokenDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\LogicalExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\PrimaryExpressionParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class ArithmeticExpressionParserTest extends TestCase
{
    public function testParseAdditiveHonorsMultiplicationPrecedence(): void
    {
        $sql = 'count + EXCLUDED.count * 2 - 1';
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new ArithmeticExpressionParser($cursor))->parseAdditive();
        self::assertSame(9, $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

    public function testParseMultiplicativeConsumesDivisionAndRemainder(): void
    {
        $sql = '24 / 2 % 5';
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new ArithmeticExpressionParser($cursor))->parseMultiplicative();
        self::assertSame(2, $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

    public function testParseUnaryConsumesNestedSigns(): void
    {
        $sql = '- - + 4';
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new ArithmeticExpressionParser($cursor))->parseUnary();
        self::assertSame(4, $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

    public function testParseUnaryNotUsesSqlTruth(): void
    {
        $sql = 'NOT FALSE';
        $cursor = new ExpressionCursor($sql, 'users', SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens());
        $result = (new ArithmeticExpressionParser($cursor))->parseUnary();
        self::assertSame(true, $result->evaluate(['count' => 4], ['count' => 3], 'users'));
        self::assertCount($cursor->index, $cursor->tokens);
    }

}
