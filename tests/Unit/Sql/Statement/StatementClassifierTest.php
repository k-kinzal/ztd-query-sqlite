<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Statement;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Statement\StatementClassifier;

#[CoversClass(StatementClassifier::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\OpaqueSqlSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner::class)]
final class StatementClassifierTest extends TestCase
{
    public function testClassifyStatementClassifySelect(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('SELECT * FROM users'));
    }

    public function testClassifyStatementClassifySelectWithLeadingWhitespace(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('  SELECT * FROM users'));
    }

    public function testClassifyStatementClassifyInsert(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testClassifyStatementClassifyInsertOrReplace(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('INSERT OR REPLACE INTO users (id, name) VALUES (1, "Alice")'));
    }

    public function testClassifyStatementClassifyReplace(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('REPLACE INTO users (id, name) VALUES (1, "Alice")'));
    }

    public function testClassifyStatementClassifyUpdate(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('UPDATE', $parser->classifyStatement('UPDATE users SET name = "Bob" WHERE id = 1'));
    }

    public function testClassifyStatementClassifyDelete(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DELETE', $parser->classifyStatement('DELETE FROM users WHERE id = 1'));
    }

    public function testClassifyStatementClassifyCreateTable(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'));
    }

    public function testClassifyStatementClassifyDropTable(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DROP_TABLE', $parser->classifyStatement('DROP TABLE users'));
    }

    public function testClassifyStatementClassifyAlterTable(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('ALTER TABLE users ADD COLUMN email TEXT'));
    }

    public function testClassifyStatementClassifyUnsupported(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('CREATE INDEX idx ON users (name)'));
    }

    public function testClassifyStatementClassifyEmpty(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement(''));
    }

    public function testClassifyStatementClassifyWithCteSelect(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithComment(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('-- comment
SELECT * FROM users'));
    }

    public function testClassifyStatementClassifyWithCteInsert(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) INSERT INTO t SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithCteUpdate(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('UPDATE', $parser->classifyStatement('WITH cte AS (SELECT 1) UPDATE t SET x = 1'));
    }

    public function testClassifyStatementClassifyWithCteDelete(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DELETE', $parser->classifyStatement('WITH cte AS (SELECT 1) DELETE FROM t WHERE x = 1'));
    }

    public function testClassifyStatementClassifyWithCteReplace(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) REPLACE INTO t (id) VALUES (1)'));
    }

    public function testClassifyStatementClassifyWithCteUnsupported(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('WITH cte AS (SELECT 1) CREATE TABLE t (id INT)'));
    }

    public function testClassifyStatementClassifyWithCteNoBody(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('WITH'));
    }

    public function testClassifyStatementClassifyWithCteQuotedParens(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement("WITH cte AS (SELECT '()') SELECT * FROM cte"));
    }

    public function testClassifyStatementClassifyWithReplaceTreatsBlockCommentsAsLexicalSeparators(): void
    {
        $parser = new StatementClassifier();
        $sql = '/* lead */WITH/* separator */[select] ([select])/* separator */AS/* separator */(VALUES ("select"))/* separator */REPLACE/* separator */INTO/* separator */`select` DEFAULT/* separator */VALUES';
        self::assertSame('INSERT', $parser->classifyStatement($sql));
    }

    public function testClassifyStatementClassifyWithUpdateTreatsLineCommentsAsLexicalSeparators(): void
    {
        $parser = new StatementClassifier();
        $sql = "WITH-- separator\n\"select\" (`select`) AS (VALUES (name))-- separator\nUPDATE/* separator */name SET name = `select`";
        self::assertSame('UPDATE', $parser->classifyStatement($sql));
    }

    public function testClassifyStatementClassifyWithDeleteIgnoresQuotedKeywordCteNames(): void
    {
        $parser = new StatementClassifier();
        $sql = "WITH name AS (VALUES (name)),-- separator\n[select] AS (VALUES (`select`))-- separator\nDELETE FROM [select]";
        self::assertSame('DELETE', $parser->classifyStatement($sql));
    }

    #[DataProvider('providerLexicalClassificationBoundaries')]
    public function testClassifyStatementUsesLexicalBoundaries(string $sql, ?string $expected): void
    {
        $parser = new StatementClassifier();
        self::assertSame($expected, $parser->classifyStatement($sql));
    }

    public function testClassifyStatementClassifyWithBlockComment(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('/* comment */ SELECT * FROM users'));
    }

    public function testClassifyStatementClassifyInsertLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement("insert into users (name) values ('Alice')"));
    }

    public function testClassifyStatementClassifyReplaceLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement("replace into users (id, name) values (1, 'Alice')"));
    }

    public function testClassifyStatementClassifyUpdateLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('UPDATE', $parser->classifyStatement("update users set name = 'Bob'"));
    }

    public function testClassifyStatementClassifyDeleteLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DELETE', $parser->classifyStatement('delete from users where id = 1'));
    }

    public function testClassifyStatementClassifyCreateTableLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('create table users (id integer)'));
    }

    public function testClassifyStatementClassifyDropTableLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DROP_TABLE', $parser->classifyStatement('drop table users'));
    }

    public function testClassifyStatementClassifyAlterTableLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('alter table users add column email text'));
    }

    public function testClassifyStatementClassifyCreateTemporaryTableLowercase(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('create temporary table tmp (id integer)'));
    }

    public function testClassifyStatementClassifyWithCteDoubleQuoteInBody(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT "col") SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithCteInsertV2(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) INSERT INTO t (a) SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithCteUpdateV2(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('UPDATE', $parser->classifyStatement('WITH cte AS (SELECT 1) UPDATE t SET a = 1'));
    }

    public function testClassifyStatementClassifyWithCteDeleteV2(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DELETE', $parser->classifyStatement('WITH cte AS (SELECT 1) DELETE FROM t'));
    }

    public function testClassifyStatementClassifyWithCteReplaceV2(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) REPLACE INTO t (a) VALUES (1)'));
    }

    public function testClassifyStatementClassifyWithCteUnsupportedV2(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('WITH cte AS (SELECT 1) CREATE TABLE t (a INTEGER)'));
    }

    public function testClassifyStatementClassifyWithCteNoBodyNoClosure(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('WITH'));
    }

    public function testClassifyStatementClassifyWithCteSingleQuoteInBody(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement("WITH cte AS (SELECT 'val') SELECT * FROM cte"));
    }

    public function testClassifyStatementClassifyWithCteNestedParens(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT (1 + (2 * 3))) SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithCteEscapedSingleQuote(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement("WITH cte AS (SELECT 'it''s') SELECT * FROM cte"));
    }

    public function testClassifyStatementClassifyWithCteEscapedDoubleQuote(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT "a""b") SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithCteClosingParenAtZeroDepth(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1)) SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithCteMultipleCtes(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH a AS (SELECT 1), b AS (SELECT 2) SELECT * FROM a, b'));
    }

    public function testClassifyStatementClassifyWithCteKeywordInMiddleOfWord(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifySelectContainingCreateTableInString(): void
    {
        $parser = new StatementClassifier();
        $result = $parser->classifyStatement("SELECT 'CREATE TABLE foo' FROM t");
        self::assertSame('SELECT', $result);
    }

    public function testClassifyStatementClassifySelectContainingDropTableInString(): void
    {
        $parser = new StatementClassifier();
        $result = $parser->classifyStatement("SELECT 'DROP TABLE foo' FROM t");
        self::assertSame('SELECT', $result);
    }

    public function testClassifyStatementClassifySelectContainingAlterTableInString(): void
    {
        $parser = new StatementClassifier();
        $result = $parser->classifyStatement("SELECT 'ALTER TABLE foo' FROM t");
        self::assertSame('SELECT', $result);
    }

    public function testClassifyStatementClassifyNonKeywordContainingCreateTableReturnsNull(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('PRAGMA CREATE TABLE foo'));
    }

    public function testClassifyStatementClassifyNonKeywordContainingDropTableReturnsNull(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('PRAGMA DROP TABLE foo'));
    }

    public function testClassifyStatementClassifyNonKeywordContainingAlterTableReturnsNull(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('PRAGMA ALTER TABLE foo'));
    }

    public function testClassifyStatementClassifyCreateTableCaseInsensitive(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('create table foo (id int)'));
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('Create Table foo (id int)'));
    }

    public function testClassifyStatementClassifyDropTableCaseInsensitive(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DROP_TABLE', $parser->classifyStatement('drop table foo'));
        self::assertSame('DROP_TABLE', $parser->classifyStatement('Drop Table foo'));
    }

    public function testClassifyStatementClassifyAlterTableCaseInsensitive(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('alter table foo add column x int'));
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('Alter Table foo add column x int'));
    }

    public function testClassifyStatementClassifyWithSelectCte(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithInsertCte(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) INSERT INTO t SELECT * FROM cte'));
    }

    public function testClassifyStatementClassifyWithUpdateCte(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('UPDATE', $parser->classifyStatement('WITH cte AS (SELECT 1) UPDATE t SET a = 1'));
    }

    public function testClassifyStatementClassifyWithDeleteCte(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('DELETE', $parser->classifyStatement('WITH cte AS (SELECT 1) DELETE FROM t'));
    }

    public function testClassifyStatementClassifyWithCteQuotedString(): void
    {
        $parser = new StatementClassifier();
        $result = $parser->classifyStatement("WITH cte AS (SELECT ')') SELECT * FROM cte");
        self::assertSame('SELECT', $result);
    }

    public function testClassifyStatementClassifyWithCteDoubleQuotedIdentifier(): void
    {
        $parser = new StatementClassifier();
        $result = $parser->classifyStatement('WITH cte AS (SELECT "col)") SELECT * FROM cte');
        self::assertSame('SELECT', $result);
    }

    public function testClassifyStatementClassifyCommentOnlyReturnsNull(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('-- just a comment'));
    }

    public function testClassifyStatementClassifyBlockCommentOnlyReturnsNull(): void
    {
        $parser = new StatementClassifier();
        self::assertNull($parser->classifyStatement('/* block comment only */'));
    }

    /**
     * @return Generator<string, array{string, ?string}>
     */
    public static function providerLexicalClassificationBoundaries(): Generator
    {
        yield 'with requires completed group' => ['WITH SELECT', null];
        yield 'line comment with newline' => ["-- SELECT\nUPDATE name SET value = 1", 'UPDATE'];
        yield 'line comment with carriage return' => ["-- SELECT\rDELETE FROM name", 'DELETE'];
        yield 'hash comment' => ["# SELECT\nINSERT INTO name DEFAULT VALUES", 'INSERT'];
        yield 'line comment at end' => ['-- SELECT', null];
        yield 'minimal block comment' => ['/**/SELECT 1', 'SELECT'];
        yield 'overlapping block delimiter is unterminated' => ['/*/SELECT 1', null];
        yield 'block comment closing boundary' => ['/**/*SELECT 1', 'SELECT'];
        yield 'unterminated block comment' => ['/* SELECT */ UPDATE /* SELECT', 'UPDATE'];
        yield 'double quoted keyword' => ['"SELECT" UPDATE name SET value = 1', 'UPDATE'];
        yield 'backtick quoted keyword' => ['`SELECT` DELETE FROM name', 'DELETE'];
        yield 'empty single quoted string' => ["''SELECT 1", 'SELECT'];
        yield 'empty double quoted identifier' => ['""SELECT 1', 'SELECT'];
        yield 'doubled single quote' => ["'value''SELECT' UPDATE name SET value = 1", 'UPDATE'];
        yield 'doubled double quote' => ['"value""SELECT" DELETE FROM name', 'DELETE'];
        yield 'doubled backtick' => ['`value``SELECT` INSERT INTO name DEFAULT VALUES', 'INSERT'];
        yield 'unterminated single quote' => ["'SELECT UPDATE", null];
        yield 'unterminated double quote' => ['"SELECT UPDATE', null];
        yield 'empty bracket identifier' => ['[]SELECT 1', 'SELECT'];
        yield 'bracket quoted keyword' => ['[SELECT] UPDATE name SET value = 1', 'UPDATE'];
        yield 'unterminated bracket identifier' => ['[SELECT UPDATE', null];
        yield 'underscore identifier' => ['_SELECT UPDATE name SET value = 1', 'UPDATE'];
        yield 'dollar identifier' => ['$SELECT DELETE FROM name', 'DELETE'];
        yield 'numeric identifier' => ['1SELECT INSERT INTO name DEFAULT VALUES', 'INSERT'];
        yield 'parenthesized keyword' => ['(SELECT) UPDATE name SET value = 1', 'UPDATE'];
        yield 'nested parenthesized keyword' => ['((SELECT)) DELETE FROM name', 'DELETE'];
        yield 'temporary non-table create' => ['CREATE TEMPORARY INDEX name', null];
    }

    public function testClassifyKeywordsDistinguishesTemporaryTables(): void
    {
        $parser = new StatementClassifier();
        self::assertSame('CREATE_TABLE', $parser->classifyKeywords('CREATE', 'TEMP', 'TABLE'));
        self::assertSame('CREATE_TABLE', $parser->classifyKeywords('CREATE', 'TEMPORARY', 'TABLE'));
        self::assertNull($parser->classifyKeywords('CREATE', 'TEMP', 'VIEW'));
        self::assertSame('INSERT', $parser->classifyKeywords('REPLACE', null, null));
        self::assertNull($parser->classifyKeywords('VACUUM', null, null));
    }

}
