<?php

declare(strict_types=1);

namespace Tests\Unit\Schema\Create;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Schema\Create\TableBodyParser;

#[CoversClass(TableBodyParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class TableBodyParserTest extends TestCase
{
    public function testTableBodyValidatesFramingAndOptions(): void
    {
        $parser = new TableBodyParser();
        self::assertSame('id INTEGER, name TEXT DEFAULT \'a)\'', $parser->tableBody("CREATE TABLE users(id INTEGER, name TEXT DEFAULT 'a)') STRICT;"));
        self::assertNull($parser->tableBody('CREATE VIEW users AS SELECT 1'));
        self::assertNull($parser->tableBody('CREATE TABLE users (id INTEGER'));
        self::assertNull($parser->tableBody('CREATE TABLE users(id INTEGER) trailing'));
    }

    public function testHasValidTableOptionsRejectsDuplicatesAndTrailingCommas(): void
    {
        self::assertTrue(TableBodyParser::hasValidTableOptions(''));
        self::assertTrue(TableBodyParser::hasValidTableOptions(' STRICT, WITHOUT ROWID;'));
        self::assertTrue(TableBodyParser::hasValidTableOptions(' WITHOUT ROWID, STRICT'));
        self::assertFalse(TableBodyParser::hasValidTableOptions(' STRICT, STRICT'));
        self::assertFalse(TableBodyParser::hasValidTableOptions(' WITHOUT ROWID, WITHOUT ROWID'));
        self::assertFalse(TableBodyParser::hasValidTableOptions(' STRICT,'));
        self::assertFalse(TableBodyParser::hasValidTableOptions(' STRICT WITHOUT ROWID'));
        self::assertFalse(TableBodyParser::hasValidTableOptions(' WITHOUT'));
    }

    public function testHasWithoutRowidRequiresTopLevelOption(): void
    {
        self::assertTrue(TableBodyParser::hasWithoutRowid('CREATE TABLE users(id INTEGER) WITHOUT ROWID'));
        self::assertFalse(TableBodyParser::hasWithoutRowid("CREATE TABLE users(name TEXT DEFAULT 'WITHOUT ROWID')"));
        self::assertFalse(TableBodyParser::hasWithoutRowid('CREATE TABLE users(id INTEGER) WITHOUT thing'));
    }

    public function testSplitColumnDefinitionsPreservesExpressionCommas(): void
    {
        self::assertSame(['id INTEGER', 'amount DECIMAL(10,2)', "name TEXT DEFAULT 'a,b'"], (new TableBodyParser())->splitColumnDefinitions("id INTEGER, amount DECIMAL(10,2), name TEXT DEFAULT 'a,b'"));
        self::assertSame([], (new TableBodyParser())->splitColumnDefinitions(''));
        self::assertSame(['id INTEGER'], (new TableBodyParser())->splitColumnDefinitions('id INTEGER, '));
    }

}
