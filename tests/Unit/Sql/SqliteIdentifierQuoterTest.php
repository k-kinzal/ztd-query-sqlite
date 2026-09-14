<?php

declare(strict_types=1);

namespace Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;

#[CoversClass(SqliteIdentifierQuoter::class)]
final class SqliteIdentifierQuoterTest extends \PHPUnit\Framework\TestCase
{
    public function testQuoteReturnsNonEmptyString(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $result = $quoter->quote('x');
        self::assertNotEmpty($result);
    }

    public function testQuoteWrapsIdentifier(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $char = '"';
        $result = $quoter->quote('table_name');
        self::assertStringStartsWith($char, $result);
        self::assertStringEndsWith($char, $result);
    }

    public function testQuoteEscapesQuoteCharacterInIdentifier(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $char = '"';
        $identifier = 'col' . $char . 'name';
        $result = $quoter->quote($identifier);
        self::assertNotEmpty($result);
        self::assertStringStartsWith($char, $result);
        self::assertStringEndsWith($char, $result);
        $simpleQuoted = $char . $identifier . $char;
        self::assertGreaterThanOrEqual(strlen($simpleQuoted), strlen($result), 'Escaped identifier should be at least as long as non-escaped form');
    }

    public function testQuoteProducesExactResult(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $char = '"';
        $result = $quoter->quote('users');
        self::assertSame($char . 'users' . $char, $result);
    }

    public function testQuoteEscapesEmbeddedQuoteExactly(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $char = '"';
        $result = $quoter->quote('col' . $char . 'name');
        $expected = $char . 'col' . $char . $char . 'name' . $char;
        self::assertSame($expected, $result);
    }

    public function testQuoteIsDeterministic(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $result1 = $quoter->quote('my_table');
        $result2 = $quoter->quote('my_table');
        self::assertSame($result1, $result2);
    }

    public function testQuotedIdentifierContainsOriginalName(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $identifier = 'column_name';
        $result = $quoter->quote($identifier);
        self::assertStringContainsString($identifier, $result);
    }

    public function testQuoteSimpleIdentifier(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('"users"', $quoter->quote('users'));
    }

    public function testQuoteIdentifierWithSpaces(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('"my table"', $quoter->quote('my table'));
    }

    public function testQuoteIdentifierWithDoubleQuotes(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('"col""name"', $quoter->quote('col"name'));
    }

    public function testQuoteAlreadyQuotedIdentifier(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('"users"', $quoter->quote('"users"'));
    }

    public function testQuoteAlreadyQuotedWithEscapedQuotes(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('"col""name"', $quoter->quote('"col""name"'));
    }

    public function testQuoteStartsAndEndsWithDoubleQuote(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $result = $quoter->quote('test_table');
        self::assertStringStartsWith('"', $result);
        self::assertStringEndsWith('"', $result);
    }

    public function testDeterminism(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $result1 = $quoter->quote('users');
        $result2 = $quoter->quote('users');
        self::assertSame($result1, $result2);
    }

    public function testContainment(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        $original = 'my_table';
        $result = $quoter->quote($original);
        self::assertStringContainsString($original, $result);
    }

    public function testQuoteEmptyQuotedIdentifier(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('""', $quoter->quote('""'));
    }

    public function testQuoteSingleCharIdentifier(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('"x"', $quoter->quote('x'));
    }

    public function testQuoteSingleDoubleQuote(): void
    {
        $quoter = new SqliteIdentifierQuoter();
        self::assertSame('""""', $quoter->quote('"'));
    }
}
