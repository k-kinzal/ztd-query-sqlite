<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteInMemoryAttachStatement;

#[CoversClass(SqliteInMemoryAttachStatement::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteInMemoryAttachStatementTest extends TestCase
{
    public function testAcceptsOnlyLiteralInMemoryAttachments(): void
    {
        self::assertTrue(SqliteInMemoryAttachStatement::isSafe("ATTACH ':memory:' AS db2"));
        self::assertTrue(SqliteInMemoryAttachStatement::isSafe("ATTACH DATABASE ':memory:' AS \"db2\";"));
        self::assertTrue(SqliteInMemoryAttachStatement::isSafe("ATTACH /* safe */ ':memory:' AS [db2]"));
        self::assertTrue(SqliteInMemoryAttachStatement::isSafe("attach ':memory:' as `db2`"));
        self::assertTrue(SqliteInMemoryAttachStatement::isSafe("ATTACH ':memory:' AS \"db alias\""));
    }

    public function testRejectsPersistentOrDynamicAttachments(): void
    {
        self::assertFalse(SqliteInMemoryAttachStatement::isSafe("ATTACH 'test.sqlite' AS db2"));
        self::assertFalse(SqliteInMemoryAttachStatement::isSafe("ATTACH ('file:' || :name) AS db2"));
        self::assertFalse(SqliteInMemoryAttachStatement::isSafe("ATTACH ':memory:' AS db2; DELETE FROM users"));
        self::assertFalse(SqliteInMemoryAttachStatement::isSafe("DETACH DATABASE db2"));
    }

    #[DataProvider('providerIncompleteOrExtendedAttachmentShapes')]
    public function testRejectsIncompleteOrExtendedAttachmentShapes(string $sql): void
    {
        self::assertFalse(SqliteInMemoryAttachStatement::isSafe($sql));
    }

    /** @return \Generator<string, array{string}> */
    public static function providerIncompleteOrExtendedAttachmentShapes(): \Generator
    {
        yield 'empty' => [''];
        yield 'keyword only' => ['ATTACH'];
        yield 'path without alias' => ["ATTACH ':memory:'"];
        yield 'missing alias' => ["ATTACH ':memory:' AS"];
        yield 'database missing alias' => ["ATTACH DATABASE ':memory:' AS"];
        yield 'missing as' => ["ATTACH ':memory:' db2"];
        yield 'extra token' => ["ATTACH ':memory:' AS db2 extra"];
        yield 'trailing closing parenthesis' => ["ATTACH ':memory:' AS db2 )"];
        yield 'string alias' => ["ATTACH ':memory:' AS 'db2'"];
        yield 'empty bracket alias' => ["ATTACH ':memory:' AS []"];
        yield 'unterminated bracket alias' => ["ATTACH ':memory:' AS [db2"];
        yield 'numeric bracket alias' => ["ATTACH ':memory:' AS [42]"];
        yield 'second statement' => ["ATTACH ':memory:' AS db2; ATTACH ':memory:' AS db3"];
    }
}
