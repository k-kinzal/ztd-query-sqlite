<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Dml\Insert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Dml\Insert\InsertClauseParser;

#[CoversClass(InsertClauseParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\ValueListParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\OpaqueSqlSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
final class InsertClauseParserTest extends TestCase
{
    public function testExtractInsertColumns(): void
    {
        $parser = new InsertClauseParser();
        $columns = $parser->extractInsertColumns('INSERT INTO users (id, name, email) VALUES (1, "Alice", "a@b.com")');
        self::assertSame(['id', 'name', 'email'], $columns);
    }

    public function testExtractInsertColumnsQuoted(): void
    {
        $parser = new InsertClauseParser();
        $columns = $parser->extractInsertColumns('INSERT INTO users ("id", "name") VALUES (1, "Alice")');
        self::assertSame(['id', 'name'], $columns);
    }

    public function testExtractInsertColumnsNoColumns(): void
    {
        $parser = new InsertClauseParser();
        $columns = $parser->extractInsertColumns('INSERT INTO users VALUES (1, "Alice")');
        self::assertSame([], $columns);
    }

    public function testExtractInsertValues(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues("INSERT INTO users (id, name) VALUES (1, 'Alice')");
        self::assertCount(1, $values);
        self::assertSame(['1', "'Alice'"], $values[0]);
    }

    public function testExtractInsertValuesMultipleRows(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
        self::assertCount(2, $values);
        self::assertSame(['1', "'Alice'"], $values[0]);
        self::assertSame(['2', "'Bob'"], $values[1]);
    }

    public function testHasOnConflict(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasOnConflict('INSERT INTO users (id, name) VALUES (1, "Alice") ON CONFLICT (id) DO UPDATE SET name = "Alice"'));
        self::assertFalse($parser->hasOnConflict('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testIsReplace(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isReplace('REPLACE INTO users (id, name) VALUES (1, "Alice")'));
        self::assertTrue($parser->isReplace('INSERT OR REPLACE INTO users (id, name) VALUES (1, "Alice")'));
        self::assertFalse($parser->isReplace('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testIsInsertIgnore(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isInsertIgnore('INSERT OR IGNORE INTO users (id, name) VALUES (1, "Alice")'));
        self::assertFalse($parser->isInsertIgnore('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testHasInsertSelect(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasInsertSelect('INSERT INTO users (id, name) SELECT id, name FROM temp_users'));
        self::assertFalse($parser->hasInsertSelect("INSERT INTO users (name) VALUES ('Alice')"));
    }

    public function testExtractInsertSelect(): void
    {
        $parser = new InsertClauseParser();
        $select = $parser->extractInsertSelect('INSERT INTO users (id, name) SELECT id, name FROM temp_users');
        self::assertSame('SELECT id, name FROM temp_users', $select);
    }

    public function testExtractInsertValuesNoValues(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues('INSERT INTO users DEFAULT VALUES');
        self::assertSame([], $values);
    }

    public function testExtractInsertValuesWithQuotedStrings(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues("INSERT INTO t (a) VALUES ('it''s')");
        self::assertCount(1, $values);
        self::assertSame(["'it''s'"], $values[0]);
    }

    public function testExtractInsertValuesWithNestedParens(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues('INSERT INTO t (a) VALUES ((1 + 2))');
        self::assertCount(1, $values);
        self::assertSame(['(1 + 2)'], $values[0]);
    }

    public function testHasInsertSelectFalseWithValues(): void
    {
        $parser = new InsertClauseParser();
        self::assertFalse($parser->hasInsertSelect('INSERT INTO t (id) VALUES (1)'));
    }

    public function testExtractInsertSelectNull(): void
    {
        $parser = new InsertClauseParser();
        self::assertNull($parser->extractInsertSelect('INSERT INTO t (id) VALUES (1)'));
    }

    public function testExtractInsertSelectWithColumns(): void
    {
        $parser = new InsertClauseParser();
        $select = $parser->extractInsertSelect('INSERT INTO t (id, name) SELECT id, name FROM s');
        self::assertSame('SELECT id, name FROM s', $select);
    }

    public function testExtractInsertValuesWithQuotedDoubleQuote(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues('INSERT INTO t (a) VALUES ("hello ""world""")');
        self::assertCount(1, $values);
    }

    public function testIsReplaceWithComment(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isReplace('/* comment */ REPLACE INTO t (id) VALUES (1)'));
    }

    public function testIsInsertIgnoreWithComment(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isInsertIgnore('/* comment */ INSERT OR IGNORE INTO t (id) VALUES (1)'));
    }

    public function testExtractInsertValuesMultipleWithWhitespace(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues('INSERT INTO t (a, b) VALUES (1, 2) , (3, 4)');
        self::assertCount(2, $values);
        self::assertSame(['1', '2'], $values[0]);
        self::assertSame(['3', '4'], $values[1]);
    }

    public function testExtractInsertColumnsLowercase(): void
    {
        $parser = new InsertClauseParser();
        $columns = $parser->extractInsertColumns("insert into users (id, name) values (1, 'Alice')");
        self::assertSame(['id', 'name'], $columns);
    }

    public function testExtractInsertValuesLowercase(): void
    {
        $parser = new InsertClauseParser();
        $values = $parser->extractInsertValues("insert into users (id, name) values (1, 'Alice')");
        self::assertCount(1, $values);
    }

    public function testHasOnConflictLowercase(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasOnConflict("insert into t (id) values (1) on conflict (id) do update set name = 'x'"));
    }

    public function testIsReplaceLowercase(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isReplace('replace into users (id) values (1)'));
    }

    public function testIsInsertIgnoreLowercase(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isInsertIgnore('insert or ignore into users (id) values (1)'));
    }

    public function testHasInsertSelectLowercase(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasInsertSelect('insert into t (id) select id from s'));
    }

    public function testExtractInsertSelectLowercase(): void
    {
        $parser = new InsertClauseParser();
        $select = $parser->extractInsertSelect('insert into t (id) select id from s');
        self::assertNotNull($select);
        self::assertStringContainsString('select', $select);
    }

    public function testExtractInsertColumnsWithSelectKeyword(): void
    {
        $parser = new InsertClauseParser();
        $columns = $parser->extractInsertColumns('INSERT INTO t (id, name) SELECT id, name FROM s');
        self::assertSame(['id', 'name'], $columns);
    }

    public function testExtractInsertValuesParseValueSetsMultipleRows(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y')");
        self::assertCount(2, $result);
        self::assertSame(['1', "'x'"], $result[0]);
        self::assertSame(['2', "'y'"], $result[1]);
    }

    public function testExtractInsertValuesParseValueSetsNestedParens(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) VALUES (COALESCE(1, 2))');
        self::assertCount(1, $result);
        self::assertSame(['COALESCE(1, 2)'], $result[0]);
    }

    public function testExtractInsertValuesParseValueSetsQuotedStringWithComma(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a, b) VALUES ('a,b', 1)");
        self::assertCount(1, $result);
        self::assertSame(["'a,b'", '1'], $result[0]);
    }

    public function testExtractInsertValuesParseValueSetsEscapedQuotes(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a) VALUES ('it''s')");
        self::assertCount(1, $result);
        self::assertSame(["'it''s'"], $result[0]);
    }

    public function testExtractInsertValuesParseValueSetsDoubleQuotedString(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) VALUES ("hello")');
        self::assertCount(1, $result);
        self::assertSame(['"hello"'], $result[0]);
    }

    public function testExtractInsertValuesParseValueSetsNoValuesKeyword(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) SELECT 1');
        self::assertSame([], $result);
    }

    public function testExtractInsertValuesParseValueSetsEmptyAfterValues(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) VALUES');
        self::assertSame([], $result);
    }

    public function testExtractInsertColumnsNoColumnsV2(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertColumns('INSERT INTO t VALUES (1, 2)');
        self::assertSame([], $result);
    }

    public function testExtractInsertColumnsQuotedV2(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertColumns('INSERT INTO t ("a", "b") VALUES (1, 2)');
        self::assertSame(['a', 'b'], $result);
    }

    public function testExtractInsertColumnsWithSelect(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertColumns('INSERT INTO t (a, b) SELECT 1, 2');
        self::assertSame(['a', 'b'], $result);
    }

    public function testHasOnConflictTrue(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasOnConflict('INSERT INTO t (a) VALUES (1) ON CONFLICT (a) DO UPDATE SET a = 2'));
    }

    public function testHasOnConflictFalse(): void
    {
        $parser = new InsertClauseParser();
        self::assertFalse($parser->hasOnConflict('INSERT INTO t (a) VALUES (1)'));
    }

    public function testIsReplaceWithReplace(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isReplace('REPLACE INTO t (a) VALUES (1)'));
    }

    public function testIsReplaceWithInsertOrReplace(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isReplace('INSERT OR REPLACE INTO t (a) VALUES (1)'));
    }

    public function testIsReplaceWithRegularInsert(): void
    {
        $parser = new InsertClauseParser();
        self::assertFalse($parser->isReplace('INSERT INTO t (a) VALUES (1)'));
    }

    public function testIsInsertIgnoreTrue(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isInsertIgnore('INSERT OR IGNORE INTO t (a) VALUES (1)'));
    }

    public function testIsInsertIgnoreFalse(): void
    {
        $parser = new InsertClauseParser();
        self::assertFalse($parser->isInsertIgnore('INSERT INTO t (a) VALUES (1)'));
    }

    public function testHasInsertSelectTrue(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasInsertSelect('INSERT INTO t (a) SELECT 1'));
    }

    public function testHasInsertSelectFalse(): void
    {
        $parser = new InsertClauseParser();
        self::assertFalse($parser->hasInsertSelect('INSERT INTO t (a) VALUES (1)'));
    }

    public function testExtractInsertSelectPresent(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertSelect('INSERT INTO t (a) SELECT 1 FROM dual');
        self::assertSame('SELECT 1 FROM dual', $result);
    }

    public function testExtractInsertSelectAbsent(): void
    {
        $parser = new InsertClauseParser();
        self::assertNull($parser->extractInsertSelect('INSERT INTO t (a) VALUES (1)'));
    }

    public function testHasInsertSelectWithColumnsAndSelect(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasInsertSelect('INSERT INTO t (a, b) SELECT 1, 2'));
    }

    public function testExtractInsertSelectWithColumnsV2(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertSelect('INSERT INTO t (a, b) SELECT 1, 2 FROM dual');
        self::assertSame('SELECT 1, 2 FROM dual', $result);
    }

    public function testExtractInsertValuesParseValueSetsEmptyValueInRow(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a, b) VALUES (1, '')");
        self::assertCount(1, $result);
        self::assertSame(['1', "''"], $result[0]);
    }

    public function testIsReplaceCaseInsensitive(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isReplace('insert or replace into t values (1)'));
        self::assertTrue($parser->isReplace('INSERT OR REPLACE INTO t VALUES (1)'));
    }

    public function testIsReplaceNotMatchedInMiddle(): void
    {
        $parser = new InsertClauseParser();
        self::assertFalse($parser->isReplace("SELECT 'INSERT OR REPLACE' FROM t"));
    }

    public function testIsInsertIgnoreCaseInsensitive(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isInsertIgnore('insert or ignore into t values (1)'));
        self::assertTrue($parser->isInsertIgnore('INSERT OR IGNORE INTO t VALUES (1)'));
    }

    public function testIsInsertIgnoreNotMatchedInMiddle(): void
    {
        $parser = new InsertClauseParser();
        self::assertFalse($parser->isInsertIgnore("SELECT 'INSERT OR IGNORE' FROM t"));
    }

    public function testExtractInsertValuesReturnsEmptyWithoutValuesKeyword(): void
    {
        $parser = new InsertClauseParser();
        self::assertSame([], $parser->extractInsertValues('INSERT INTO t SELECT * FROM s'));
    }

    public function testHasOnConflictCaseInsensitive(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasOnConflict('insert into t values (1) on conflict do update set a = 1'));
    }

    public function testHasInsertSelectCaseInsensitive(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasInsertSelect('insert into t (a) select b from s'));
    }

    public function testExtractInsertSelectCaseInsensitive(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertSelect('insert into t (a) select b from s');
        self::assertNotNull($result);
        self::assertStringContainsString('select', $result);
    }

    public function testExtractInsertSelectReturnsNullWithoutSelect(): void
    {
        $parser = new InsertClauseParser();
        self::assertNull($parser->extractInsertSelect('INSERT INTO t VALUES (1)'));
    }

    public function testExtractInsertColumnsFromReplace(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertColumns('REPLACE INTO t (a, b) VALUES (1, 2)');
        self::assertSame(['a', 'b'], $result);
    }

    public function testExtractInsertValuesParseValueSetsMultiple(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues("INSERT INTO t VALUES (1, 'a'), (2, 'b')");
        self::assertCount(2, $result);
    }

    public function testExtractInsertValuesParseValueSetsWithQuotedParen(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues("INSERT INTO t VALUES ('(1)', 'a')");
        self::assertCount(1, $result);
        self::assertSame(["'(1)'", "'a'"], $result[0]);
    }

    public function testExtractInsertSelectMultiline(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertSelect("INSERT INTO t (a) SELECT b\nFROM s");
        self::assertNotNull($result);
        self::assertStringContainsString('SELECT', $result);
    }

    public function testHasInsertSelectMultiline(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasInsertSelect("INSERT INTO t\n(a)\nSELECT b FROM s"));
    }

    public function testHasOnConflictMultiline(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->hasOnConflict("INSERT INTO t VALUES (1)\nON CONFLICT\n(id) DO UPDATE SET a = 1"));
    }

    public function testExtractInsertColumnsMultiline(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertColumns("INSERT INTO t\n(a, b)\nVALUES (1, 2)");
        self::assertSame(['a', 'b'], $result);
    }

    public function testExtractInsertValuesMultiline(): void
    {
        $parser = new InsertClauseParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a)\nVALUES\n(1)");
        self::assertCount(1, $result);
    }

    public function testIsReplaceCaseInsensitiveOrReplace(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isReplace('Insert Or Replace INTO t VALUES (1)'));
    }

    public function testIsInsertIgnoreMixedCase(): void
    {
        $parser = new InsertClauseParser();
        self::assertTrue($parser->isInsertIgnore('Insert Or Ignore INTO t VALUES (1)'));
    }

    public function testHasInsertSelectInsertValuesSourceIgnoresQuotedKeywordIdentifiers(): void
    {
        $parser = new InsertClauseParser();
        $tableSelect = "INSERT INTO \"select\" (id, val) VALUES (1, 'table-select')";
        $tableValues = "INSERT INTO \"values\" (id, val) VALUES (2, 'table-values')";
        $columnKeywords = "INSERT INTO test (id, \"select\", \"values\") VALUES (3, 'column-select', 'column-values')";

        self::assertFalse($parser->hasInsertSelect($tableSelect));
        self::assertNull($parser->extractInsertSelect($tableSelect));
        self::assertSame([['1', "'table-select'"]], $parser->extractInsertValues($tableSelect));
        self::assertFalse($parser->hasInsertSelect($tableValues));
        self::assertNull($parser->extractInsertSelect($tableValues));
        self::assertSame([['2', "'table-values'"]], $parser->extractInsertValues($tableValues));
        self::assertFalse($parser->hasInsertSelect($columnKeywords));
        self::assertNull($parser->extractInsertSelect($columnKeywords));
        self::assertSame([['3', "'column-select'", "'column-values'"]], $parser->extractInsertValues($columnKeywords));
    }

    public function testHasInsertSelectInsertSelectSourceStartsAfterQuotedKeywordIdentifiers(): void
    {
        $parser = new InsertClauseParser();
        $sql = 'INSERT INTO "select" ("select", "values") SELECT 1, 2';

        self::assertTrue($parser->hasInsertSelect($sql));
        self::assertSame('SELECT 1, 2', $parser->extractInsertSelect($sql));
    }

    public function testExtractInsertValuesInsertSourceRequiresInsertOrReplaceStatement(): void
    {
        $parser = new InsertClauseParser();

        self::assertSame([], $parser->extractInsertValues('SELECT VALUES (1)'));
        self::assertSame([], $parser->extractInsertValues('VALUES (1)'));
        self::assertFalse($parser->hasInsertSelect('VALUES SELECT 1'));
        self::assertNull($parser->extractInsertSelect('VALUES SELECT 1'));
    }

    public function testExtractInsertValuesReplaceUsesValuesAndSelectSources(): void
    {
        $parser = new InsertClauseParser();
        $values = 'REPLACE INTO target VALUES (1)';
        $select = 'REPLACE INTO target SELECT 1';

        self::assertSame([['1']], $parser->extractInsertValues($values));
        self::assertFalse($parser->hasInsertSelect($values));
        self::assertTrue($parser->hasInsertSelect($select));
        self::assertSame('SELECT 1', $parser->extractInsertSelect($select));
    }

    public function testExtractInsertSelectInsertSelectSourceTrimsTrailingWhitespace(): void
    {
        $parser = new InsertClauseParser();

        self::assertSame('SELECT 1', $parser->extractInsertSelect("INSERT INTO target SELECT 1 \n\t"));
    }

    public function testFindInsertSourceClauseSkipsSubqueries(): void
    {
        $parser = new InsertClauseParser();
        self::assertSame(['keyword' => 'VALUES', 'offset' => 25], $parser->findInsertSourceClause('INSERT INTO users (name) VALUES (1)'));
        self::assertNull($parser->findInsertSourceClause('INSERT INTO users (name)'));
    }

}
