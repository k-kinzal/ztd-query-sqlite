<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteUpsertExpressionParser;

#[CoversClass(SqliteUpsertExpressionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteUpsertExpressionParserTest extends TestCase
{
    #[DataProvider('providerSqliteExpressionCases')]
    public function testParsesSqliteExpressionCases(string $sql, mixed $expected): void
    {
        self::assertSame(
            $expected,
            (new SqliteUpsertExpressionParser())->parse($sql, 'items')->evaluate([], [], 'items'),
        );
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function providerSqliteExpressionCases(): iterable
    {
        yield 'chained or' => ['1 OR 0 OR 0', true];
        yield 'chained and' => ['1 AND 1 AND 0', false];
        yield 'equal' => ['1 = 2', false];
        yield 'bang not equal' => ['1 != 2', true];
        yield 'angle not equal' => ['1 <> 2', true];
        yield 'less' => ['1 < 2', true];
        yield 'less or equal' => ['1 <= 2', true];
        yield 'greater' => ['2 > 1', true];
        yield 'greater or equal' => ['2 >= 1', true];
        yield 'chained additive' => ['10 - 3 + 2', 9];
        yield 'chained multiplicative' => ['20 / 5 % 3 * 2', 2];
        yield 'nested unary minus' => ['- -2', 2];
        yield 'nested unary plus' => ['+ +2', 2];
        yield 'unary minus' => ['-2', -2];
        yield 'unary plus' => ['+2', 2];
        yield 'nested not' => ['NOT NOT TRUE', true];
        yield 'parenthesized precedence' => ['(1 + 2) * 3', 9];
        yield 'null' => ['NULL', null];
        yield 'false' => ['FALSE', false];
        yield 'underscored integer' => ['1_000', 1000];
        yield 'exponent' => ['1.5e1', 15.0];
        yield 'escaped string' => ["'it''s'", "it's"];
    }

    public function testParsesExcludedAndExistingReferences(): void
    {
        $expression = (new SqliteUpsertExpressionParser())->parse(
            '`items`.`quantity` + EXCLUDED.`quantity` * 2',
            'items',
        );

        self::assertSame(11, $expression->evaluate(['quantity' => 5], ['quantity' => 3], 'items'));
    }

    public function testUnescapesQuotedSqliteIdentifiers(): void
    {
        $parser = new SqliteUpsertExpressionParser();

        self::assertSame(
            5,
            $parser->parse('"quan""tity"', 'items')->evaluate(['quan"tity' => 5], [], 'items'),
        );
        self::assertSame(
            5,
            $parser->parse('`quan``tity`', 'items')->evaluate(['quan`tity' => 5], [], 'items'),
        );
    }

    public function testParsesPredicate(): void
    {
        $expression = (new SqliteUpsertExpressionParser())->parse(
            "score >= 80 AND excluded.name <> 'blocked'",
            'items',
        );

        self::assertTrue($expression->matches(['score' => 80], ['name' => 'ready'], 'items'));
    }

    public function testReturnsNullForUnsupportedFunction(): void
    {
        self::assertNull((new SqliteUpsertExpressionParser())->parseIfSupported('COALESCE(score, 0)', 'items'));
    }

    public function testRejectsMySqlValuesFunction(): void
    {
        $this->expectException(UnsupportedSqlException::class);

        (new SqliteUpsertExpressionParser())->parse('VALUES(quantity)', 'items');
    }

    #[DataProvider('providerInvalidSqliteExpression')]
    public function testRejectsInvalidSqliteExpression(string $sql): void
    {
        self::assertNull((new SqliteUpsertExpressionParser())->parseIfSupported($sql, 'items'));
    }

    /** @return iterable<string, array{string}> */
    public static function providerInvalidSqliteExpression(): iterable
    {
        yield 'empty' => [''];
        yield 'missing close parenthesis' => ['(1 + 2'];
        yield 'wrong close parenthesis' => ['(1 + 2 value'];
        yield 'extra close parenthesis' => ['1 + 2)'];
        yield 'missing additive operand' => ['1 +'];
        yield 'missing comparison operand' => ['1 ='];
        yield 'missing qualified column' => ['items.'];
        yield 'invalid qualified column' => ['items.+'];
        yield 'invalid comparison pair' => ['1 ! 2'];
        yield 'double equals' => ['1 == 1'];
        yield 'unknown qualifier' => ['other.value'];
        yield 'symbol primary' => ['*'];
    }
}
