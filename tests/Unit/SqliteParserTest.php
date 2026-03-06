<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteParser;

#[CoversClass(SqliteParser::class)]
final class SqliteParserTest extends TestCase
{
    public function testClassifySelect(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('SELECT * FROM users'));
    }

    public function testClassifySelectWithLeadingWhitespace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('  SELECT * FROM users'));
    }

    public function testClassifyInsert(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testClassifyInsertOrReplace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('INSERT OR REPLACE INTO users (id, name) VALUES (1, "Alice")'));
    }

    public function testClassifyReplace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('REPLACE INTO users (id, name) VALUES (1, "Alice")'));
    }

    public function testClassifyUpdate(): void
    {
        $parser = new SqliteParser();
        self::assertSame('UPDATE', $parser->classifyStatement('UPDATE users SET name = "Bob" WHERE id = 1'));
    }

    public function testClassifyDelete(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DELETE', $parser->classifyStatement('DELETE FROM users WHERE id = 1'));
    }

    public function testClassifyCreateTable(): void
    {
        $parser = new SqliteParser();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'));
    }

    public function testClassifyCreateTemporaryTable(): void
    {
        $parser = new SqliteParser();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('CREATE TEMPORARY TABLE tmp (id INTEGER)'));
    }

    public function testClassifyDropTable(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DROP_TABLE', $parser->classifyStatement('DROP TABLE users'));
    }

    public function testClassifyAlterTable(): void
    {
        $parser = new SqliteParser();
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('ALTER TABLE users ADD COLUMN email TEXT'));
    }

    public function testClassifyUnsupported(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement('CREATE INDEX idx ON users (name)'));
    }

    public function testClassifyEmpty(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement(''));
    }

    public function testClassifyWithCteSelect(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function testClassifyWithComment(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('-- comment
SELECT * FROM users'));
    }

    public function testSplitSingleStatement(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT * FROM users');
        self::assertCount(1, $result);
        self::assertSame('SELECT * FROM users', $result[0]);
    }

    public function testSplitMultipleStatements(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 1; SELECT 2");
        self::assertCount(2, $result);
        self::assertSame('SELECT 1', $result[0]);
        self::assertSame('SELECT 2', $result[1]);
    }

    public function testSplitStatementsIgnoresSemicolonInString(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 'a;b' FROM t");
        self::assertCount(1, $result);
    }

    public function testSplitStatementsIgnoresSemicolonInParentheses(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT (1;2) FROM t');
        self::assertCount(1, $result);
    }

    public function testExtractInsertTarget(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testExtractInsertTargetQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO "my table" (name) VALUES ("Alice")'));
    }

    public function testExtractUpdateTarget(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE users SET name = "Bob"'));
    }

    public function testExtractDeleteTarget(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('DELETE FROM users WHERE id = 1'));
    }

    public function testExtractCreateTableTarget(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('CREATE TABLE users (id INTEGER)'));
    }

    public function testExtractDropTableTarget(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('DROP TABLE users'));
    }

    public function testExtractAlterTableTarget(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('ALTER TABLE users ADD COLUMN email TEXT'));
    }

    public function testExtractSelectTables(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractInsertColumns(): void
    {
        $parser = new SqliteParser();
        $columns = $parser->extractInsertColumns('INSERT INTO users (id, name, email) VALUES (1, "Alice", "a@b.com")');
        self::assertSame(['id', 'name', 'email'], $columns);
    }

    public function testExtractInsertColumnsQuoted(): void
    {
        $parser = new SqliteParser();
        $columns = $parser->extractInsertColumns('INSERT INTO users ("id", "name") VALUES (1, "Alice")');
        self::assertSame(['id', 'name'], $columns);
    }

    public function testExtractInsertColumnsNoColumns(): void
    {
        $parser = new SqliteParser();
        $columns = $parser->extractInsertColumns('INSERT INTO users VALUES (1, "Alice")');
        self::assertSame([], $columns);
    }

    public function testExtractInsertValues(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues("INSERT INTO users (id, name) VALUES (1, 'Alice')");
        self::assertCount(1, $values);
        self::assertSame(['1', "'Alice'"], $values[0]);
    }

    public function testExtractInsertValuesMultipleRows(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
        self::assertCount(2, $values);
        self::assertSame(['1', "'Alice'"], $values[0]);
        self::assertSame(['2', "'Bob'"], $values[1]);
    }

    public function testExtractUpdateAssignments(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE users SET name = 'Bob', age = 30 WHERE id = 1");
        self::assertSame("'Bob'", $assignments['name']);
        self::assertSame('30', $assignments['age']);
    }

    public function testExtractWhereClause(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id = 1', $parser->extractWhereClause('SELECT * FROM users WHERE id = 1'));
    }

    public function testExtractWhereClauseWithOrderBy(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 ORDER BY id'));
    }

    public function testExtractNoWhereClause(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractWhereClause('SELECT * FROM users'));
    }

    public function testHasOnConflict(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasOnConflict('INSERT INTO users (id, name) VALUES (1, "Alice") ON CONFLICT (id) DO UPDATE SET name = "Alice"'));
        self::assertFalse($parser->hasOnConflict('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testIsReplace(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isReplace('REPLACE INTO users (id, name) VALUES (1, "Alice")'));
        self::assertTrue($parser->isReplace('INSERT OR REPLACE INTO users (id, name) VALUES (1, "Alice")'));
        self::assertFalse($parser->isReplace('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testIsInsertIgnore(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isInsertIgnore('INSERT OR IGNORE INTO users (id, name) VALUES (1, "Alice")'));
        self::assertFalse($parser->isInsertIgnore('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testExtractOnConflictUpdates(): void
    {
        $parser = new SqliteParser();
        $updates = $parser->extractOnConflictUpdates(
            'INSERT INTO users (id, name) VALUES (1, "Alice") ON CONFLICT (id) DO UPDATE SET name = excluded.name'
        );
        self::assertArrayHasKey('name', $updates);
        self::assertSame('excluded.name', $updates['name']);
    }

    public function testHasInsertSelect(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasInsertSelect('INSERT INTO users (id, name) SELECT id, name FROM temp_users'));
        self::assertFalse($parser->hasInsertSelect("INSERT INTO users (name) VALUES ('Alice')"));
    }

    public function testExtractInsertSelect(): void
    {
        $parser = new SqliteParser();
        $select = $parser->extractInsertSelect('INSERT INTO users (id, name) SELECT id, name FROM temp_users');
        self::assertSame('SELECT id, name FROM temp_users', $select);
    }

    public function testUnquoteDoubleQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->unquoteIdentifier('"users"'));
    }

    public function testUnquoteBacktickQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->unquoteIdentifier('`users`'));
    }

    public function testUnquoteBracketQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->unquoteIdentifier('[users]'));
    }

    public function testUnquoteUnquoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->unquoteIdentifier('users'));
    }

    public function testUnquoteEscapedDoubleQuotes(): void
    {
        $parser = new SqliteParser();
        self::assertSame('col"name', $parser->unquoteIdentifier('"col""name"'));
    }

    public function testStripLineComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->stripComments("SELECT 1 -- comment\nFROM t");
        self::assertStringContainsString('SELECT 1', $result);
        self::assertStringContainsString('FROM t', $result);
        self::assertStringNotContainsString('comment', $result);
    }

    public function testStripBlockComment(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT 1  FROM t', $parser->stripComments('SELECT 1 /* comment */ FROM t'));
    }

    public function testStripHashComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->stripComments("SELECT 1 # comment\nFROM t");
        self::assertStringNotContainsString('#', $result);
        self::assertStringContainsString('SELECT 1', $result);
    }

    public function testClassifyWithCteInsert(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) INSERT INTO t SELECT * FROM cte'));
    }

    public function testClassifyWithCteUpdate(): void
    {
        $parser = new SqliteParser();
        self::assertSame('UPDATE', $parser->classifyStatement('WITH cte AS (SELECT 1) UPDATE t SET x = 1'));
    }

    public function testClassifyWithCteDelete(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DELETE', $parser->classifyStatement('WITH cte AS (SELECT 1) DELETE FROM t WHERE x = 1'));
    }

    public function testClassifyWithCteReplace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) REPLACE INTO t (id) VALUES (1)'));
    }

    public function testClassifyWithCteUnsupported(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement('WITH cte AS (SELECT 1) CREATE TABLE t (id INT)'));
    }

    public function testClassifyWithCteNoBody(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement('WITH'));
    }

    public function testClassifyWithCteQuotedParens(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement("WITH cte AS (SELECT '()') SELECT * FROM cte"));
    }

    public function testSplitStatementsIgnoresSemicolonInDoubleQuote(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT "a;b" FROM t');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsHandlesLineComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 1; -- comment\nSELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesBlockComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1; /* comment */ SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesUnclosedBlockComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1 /* unclosed comment');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsHandlesLineCommentAtEnd(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1 -- trailing comment');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsHandlesEscapedSingleQuote(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 'it''s'; SELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesEscapedDoubleQuote(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT "col""name"; SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesNestedParens(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT (1 + (2 + 3)); SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsEmptyInput(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('');
        self::assertSame([], $result);
    }

    public function testSplitStatementsTrailingSemicolon(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1;');
        self::assertCount(1, $result);
    }

    public function testExtractTargetTableForSelect(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractTargetTable('SELECT * FROM users'));
    }

    public function testExtractTargetTableForUnsupported(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractTargetTable('CREATE INDEX idx ON users(name)'));
    }

    public function testExtractInsertTargetReplaceInto(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable("REPLACE INTO users (id) VALUES (1)"));
    }

    public function testExtractInsertTargetWithBackticks(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable("INSERT INTO `my table` (id) VALUES (1)"));
    }

    public function testExtractInsertTargetWithBrackets(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable("INSERT INTO [my table] (id) VALUES (1)"));
    }

    public function testExtractUpdateTargetQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable('UPDATE "my table" SET x = 1'));
    }

    public function testExtractUpdateTargetWithOrReplace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR REPLACE users SET x = 1'));
    }

    public function testExtractDeleteTargetQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable('DELETE FROM "my table" WHERE id = 1'));
    }

    public function testExtractCreateTableIfNotExists(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('CREATE TABLE IF NOT EXISTS users (id INT)'));
    }

    public function testExtractDropTableIfExists(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('DROP TABLE IF EXISTS users'));
    }

    public function testExtractSelectTablesWithAlias(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithAsAlias(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users AS u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesMultiple(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users, orders');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithWhere(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users WHERE id = 1');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithGroupBy(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users GROUP BY name');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithOrderBy(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users ORDER BY id');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithLimit(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users LIMIT 10');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesQuoted(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM "users"');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesNoFrom(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT 1');
        self::assertSame([], $tables);
    }

    public function testExtractInsertValuesNoValues(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues('INSERT INTO users DEFAULT VALUES');
        self::assertSame([], $values);
    }

    public function testExtractInsertValuesWithQuotedStrings(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues("INSERT INTO t (a) VALUES ('it''s')");
        self::assertCount(1, $values);
        self::assertSame(["'it''s'"], $values[0]);
    }

    public function testExtractInsertValuesWithNestedParens(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues('INSERT INTO t (a) VALUES ((1 + 2))');
        self::assertCount(1, $values);
        self::assertSame(['(1 + 2)'], $values[0]);
    }

    public function testExtractUpdateAssignmentsQuotedColumn(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET "name" = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
        self::assertSame("'Bob'", $assignments['name']);
    }

    public function testExtractUpdateAssignmentsBacktickColumn(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET `name` = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractUpdateAssignmentsBracketColumn(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET [name] = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractUpdateAssignmentsTableQualified(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET users.name = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractUpdateAssignmentsWithFunction(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE t SET x = COALESCE(a, 'b') WHERE id = 1");
        self::assertArrayHasKey('x', $assignments);
        self::assertStringContainsString('COALESCE', $assignments['x']);
    }

    public function testExtractUpdateAssignmentsEmpty(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments('SELECT * FROM users');
        self::assertSame([], $assignments);
    }

    public function testExtractUpdateAssignmentsWithOrderBy(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE t SET x = 1 ORDER BY id");
        self::assertArrayHasKey('x', $assignments);
        self::assertSame('1', $assignments['x']);
    }

    public function testExtractUpdateAssignmentsWithLimit(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE t SET x = 1 LIMIT 5");
        self::assertArrayHasKey('x', $assignments);
        self::assertSame('1', $assignments['x']);
    }

    public function testExtractWhereClauseWithGroupBy(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 GROUP BY name'));
    }

    public function testExtractWhereClauseWithHaving(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 HAVING count > 1'));
    }

    public function testExtractWhereClauseWithLimit(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 LIMIT 5'));
    }

    public function testExtractOrderByClause(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id DESC', $parser->extractOrderByClause('SELECT * FROM users ORDER BY id DESC'));
    }

    public function testExtractOrderByClauseWithLimit(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id', $parser->extractOrderByClause('SELECT * FROM users ORDER BY id LIMIT 5'));
    }

    public function testExtractOrderByClauseNone(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractOrderByClause('SELECT * FROM users'));
    }

    public function testExtractLimitClause(): void
    {
        $parser = new SqliteParser();
        self::assertSame('10', $parser->extractLimitClause('SELECT * FROM users LIMIT 10'));
    }

    public function testExtractLimitClauseNone(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractLimitClause('SELECT * FROM users'));
    }

    public function testExtractOnConflictUpdatesDoNothing(): void
    {
        $parser = new SqliteParser();
        $updates = $parser->extractOnConflictUpdates('INSERT INTO t (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
        self::assertSame([], $updates);
    }

    public function testExtractOnConflictUpdatesNoConflict(): void
    {
        $parser = new SqliteParser();
        $updates = $parser->extractOnConflictUpdates('INSERT INTO t (id) VALUES (1)');
        self::assertSame([], $updates);
    }

    public function testExtractOnConflictUpdatesMultiple(): void
    {
        $parser = new SqliteParser();
        $updates = $parser->extractOnConflictUpdates(
            'INSERT INTO t (id, name, age) VALUES (1, \'a\', 2) ON CONFLICT (id) DO UPDATE SET name = excluded.name, age = excluded.age'
        );
        self::assertArrayHasKey('name', $updates);
        self::assertArrayHasKey('age', $updates);
        self::assertSame('excluded.name', $updates['name']);
        self::assertSame('excluded.age', $updates['age']);
    }

    public function testHasInsertSelectFalseWithValues(): void
    {
        $parser = new SqliteParser();
        self::assertFalse($parser->hasInsertSelect("INSERT INTO t (id) VALUES (1)"));
    }

    public function testExtractInsertSelectNull(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractInsertSelect("INSERT INTO t (id) VALUES (1)"));
    }

    public function testExtractInsertSelectWithColumns(): void
    {
        $parser = new SqliteParser();
        $select = $parser->extractInsertSelect('INSERT INTO t (id, name) SELECT id, name FROM s');
        self::assertSame('SELECT id, name FROM s', $select);
    }

    public function testUnquoteIdentifierWithLeadingWhitespace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->unquoteIdentifier('  users  '));
    }

    public function testUnquoteIdentifierEscapedBackticks(): void
    {
        $parser = new SqliteParser();
        self::assertSame('col`name', $parser->unquoteIdentifier('`col``name`'));
    }

    public function testExtractInsertValuesWithQuotedDoubleQuote(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues('INSERT INTO t (a) VALUES ("hello ""world""")');
        self::assertCount(1, $values);
    }

    public function testIsReplaceWithComment(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isReplace("/* comment */ REPLACE INTO t (id) VALUES (1)"));
    }

    public function testIsInsertIgnoreWithComment(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isInsertIgnore("/* comment */ INSERT OR IGNORE INTO t (id) VALUES (1)"));
    }

    public function testExtractUpdateAssignmentsWithQuotedValue(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE t SET name = 'it''s cool' WHERE id = 1");
        self::assertArrayHasKey('name', $assignments);
        self::assertSame("'it''s cool'", $assignments['name']);
    }

    public function testExtractSelectTablesWithLeftJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithInnerJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users INNER JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithHaving(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users HAVING count(*) > 1');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithUnion(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users UNION SELECT * FROM admins');
        self::assertContains('users', $tables);
    }

    public function testSplitStatementsEmpty(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('  ;  ;  ');
        self::assertSame([], $result);
    }

    public function testExtractSelectTablesEmptyExpr(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM , users');
        self::assertContains('users', $tables);
    }

    public function testExtractInsertValuesMultipleWithWhitespace(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues("INSERT INTO t (a, b) VALUES (1, 2) , (3, 4)");
        self::assertCount(2, $values);
        self::assertSame(['1', '2'], $values[0]);
        self::assertSame(['3', '4'], $values[1]);
    }

    public function testClassifyWithBlockComment(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('/* comment */ SELECT * FROM users'));
    }

    public function testClassifyInsertLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement("insert into users (name) values ('Alice')"));
    }

    public function testClassifyReplaceLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement("replace into users (id, name) values (1, 'Alice')"));
    }

    public function testClassifyUpdateLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('UPDATE', $parser->classifyStatement("update users set name = 'Bob'"));
    }

    public function testClassifyDeleteLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DELETE', $parser->classifyStatement('delete from users where id = 1'));
    }

    public function testClassifyCreateTableLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('create table users (id integer)'));
    }

    public function testClassifyDropTableLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DROP_TABLE', $parser->classifyStatement('drop table users'));
    }

    public function testClassifyAlterTableLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('alter table users add column email text'));
    }

    public function testExtractInsertTargetLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable("insert into users (name) values ('Alice')"));
    }

    public function testExtractUpdateTargetLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable("update users set name = 'Bob'"));
    }

    public function testExtractDeleteTargetLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('delete from users where id = 1'));
    }

    public function testExtractCreateTableNameLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('create table users (id integer)'));
    }

    public function testExtractDropTableNameLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('drop table users'));
    }

    public function testExtractAlterTableNameLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('alter table users add column email text'));
    }

    public function testExtractSelectTablesLowercase(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('select * from users where id = 1');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithJoinLowercase(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('select * from users join orders on users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractInsertColumnsLowercase(): void
    {
        $parser = new SqliteParser();
        $columns = $parser->extractInsertColumns("insert into users (id, name) values (1, 'Alice')");
        self::assertSame(['id', 'name'], $columns);
    }

    public function testExtractInsertValuesLowercase(): void
    {
        $parser = new SqliteParser();
        $values = $parser->extractInsertValues("insert into users (id, name) values (1, 'Alice')");
        self::assertCount(1, $values);
    }

    public function testExtractUpdateAssignmentsLowercase(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("update users set name = 'Bob' where id = 1");
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractWhereClauseLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id = 1', $parser->extractWhereClause('select * from users where id = 1'));
    }

    public function testExtractOrderByClauseLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id', $parser->extractOrderByClause('select * from users order by id'));
    }

    public function testExtractLimitClauseLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('10', $parser->extractLimitClause('select * from users limit 10'));
    }

    public function testHasOnConflictLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasOnConflict("insert into t (id) values (1) on conflict (id) do update set name = 'x'"));
    }

    public function testIsReplaceLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isReplace('replace into users (id) values (1)'));
    }

    public function testIsInsertIgnoreLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isInsertIgnore('insert or ignore into users (id) values (1)'));
    }

    public function testExtractOnConflictUpdatesLowercase(): void
    {
        $parser = new SqliteParser();
        $updates = $parser->extractOnConflictUpdates(
            "insert into t (id, name) values (1, 'a') on conflict (id) do update set name = excluded.name"
        );
        self::assertArrayHasKey('name', $updates);
    }

    public function testHasInsertSelectLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasInsertSelect('insert into t (id) select id from s'));
    }

    public function testExtractInsertSelectLowercase(): void
    {
        $parser = new SqliteParser();
        $select = $parser->extractInsertSelect('insert into t (id) select id from s');
        self::assertNotNull($select);
        self::assertStringContainsString('select', $select);
    }

    public function testExtractUpdateTargetOrRollback(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR ROLLBACK users SET x = 1'));
    }

    public function testExtractUpdateTargetOrAbort(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR ABORT users SET x = 1'));
    }

    public function testExtractUpdateTargetOrFail(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR FAIL users SET x = 1'));
    }

    public function testExtractUpdateTargetOrIgnore(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR IGNORE users SET x = 1'));
    }

    public function testExtractSelectTablesWithGroupByLowercase(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('select * from users group by name');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithOrderByLowercase(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('select * from users order by id');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithLimitLowercase(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('select * from users limit 10');
        self::assertSame(['users'], $tables);
    }

    public function testExtractWhereClauseWithOrderByLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 order by id'));
    }

    public function testExtractWhereClauseWithLimitLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 limit 5'));
    }

    public function testExtractWhereClauseWithGroupByLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 group by name'));
    }

    public function testExtractWhereClauseWithHavingLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 having count > 1'));
    }

    public function testExtractUpdateAssignmentsWithLimitLowercase(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("update t set x = 1 limit 5");
        self::assertArrayHasKey('x', $assignments);
    }

    public function testExtractUpdateAssignmentsWithOrderByLowercase(): void
    {
        $parser = new SqliteParser();
        $assignments = $parser->extractUpdateAssignments("update t set x = 1 order by id");
        self::assertArrayHasKey('x', $assignments);
    }

    public function testExtractOrderByClauseWithLimitLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('id', $parser->extractOrderByClause('select * from users order by id limit 5'));
    }

    public function testCreateTableIfNotExistsLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('create table if not exists users (id integer)'));
    }

    public function testDropTableIfExistsLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('drop table if exists users'));
    }

    public function testExtractSelectTablesLeftJoinLowercase(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('select * from users left join orders on users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesCrossJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users CROSS JOIN orders');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesNaturalJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users NATURAL JOIN orders');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesRightJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users RIGHT JOIN orders ON 1=1');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testClassifyCreateTemporaryTableLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('create temporary table tmp (id integer)'));
    }

    public function testExtractCreateTemporaryTableNameLowercase(): void
    {
        $parser = new SqliteParser();
        self::assertSame('tmp', $parser->extractTargetTable('create temporary table tmp (id integer)'));
    }

    public function testExtractInsertColumnsWithSelectKeyword(): void
    {
        $parser = new SqliteParser();
        $columns = $parser->extractInsertColumns('INSERT INTO t (id, name) SELECT id, name FROM s');
        self::assertSame(['id', 'name'], $columns);
    }

    public function testExtractReplaceTableTarget(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable("REPLACE users (id) VALUES (1)"));
    }

    public function testStripCommentsPreservesContent(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT 1', $parser->stripComments('SELECT 1'));
    }

    public function testStripMultipleComments(): void
    {
        $parser = new SqliteParser();
        $result = $parser->stripComments("/* c1 */ SELECT /* c2 */ 1 -- c3\n");
        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('1', $result);
    }

    public function testClassifyWithCteDoubleQuoteInBody(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT "col") SELECT * FROM cte'));
    }

    public function testUnquoteIdentifierSingleCharDoubleQuote(): void
    {
        $parser = new SqliteParser();
        self::assertSame('"', $parser->unquoteIdentifier('"'));
    }

    public function testUnquoteIdentifierSingleCharBacktick(): void
    {
        $parser = new SqliteParser();
        self::assertSame('`', $parser->unquoteIdentifier('`'));
    }

    public function testUnquoteIdentifierSingleCharBracket(): void
    {
        $parser = new SqliteParser();
        self::assertSame('[', $parser->unquoteIdentifier('['));
    }

    public function testUnquoteIdentifierEmptyDoubleQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('', $parser->unquoteIdentifier('""'));
    }

    public function testUnquoteIdentifierEmptyBacktickQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('', $parser->unquoteIdentifier('``'));
    }

    public function testUnquoteIdentifierEmptyBracketQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('', $parser->unquoteIdentifier('[]'));
    }

    public function testUnquoteIdentifierDoubleQuoteWithEscapes(): void
    {
        $parser = new SqliteParser();
        self::assertSame('a"b', $parser->unquoteIdentifier('"a""b"'));
    }

    public function testUnquoteIdentifierBacktickWithEscapes(): void
    {
        $parser = new SqliteParser();
        self::assertSame('a`b', $parser->unquoteIdentifier('`a``b`'));
    }

    public function testUnquoteIdentifierBracketContent(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my col', $parser->unquoteIdentifier('[my col]'));
    }

    public function testUnquoteIdentifierUnquotedReturnsAsIs(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->unquoteIdentifier('users'));
    }

    public function testUnquoteIdentifierWithWhitespace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->unquoteIdentifier('  users  '));
    }

    public function testUnquoteIdentifierMismatchedQuotes(): void
    {
        $parser = new SqliteParser();
        self::assertSame('"abc', $parser->unquoteIdentifier('"abc'));
    }

    public function testSplitStatementsWithEscapedSingleQuotes(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 'it''s'; SELECT 2");
        self::assertCount(2, $result);
        self::assertStringContainsString("it''s", $result[0]);
        self::assertSame('SELECT 2', $result[1]);
    }

    public function testSplitStatementsWithEscapedDoubleQuotes(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT "a""b"; SELECT 2');
        self::assertCount(2, $result);
        self::assertStringContainsString('a""b', $result[0]);
        self::assertSame('SELECT 2', $result[1]);
    }

    public function testSplitStatementsWithLineComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 1 -- comment\n; SELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsWithLineCommentNoNewline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1 -- comment at end');
        self::assertCount(1, $result);
        self::assertStringContainsString('SELECT 1', $result[0]);
        self::assertStringContainsString('-- comment at end', $result[0]);
    }

    public function testSplitStatementsWithBlockComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT /* block */ 1; SELECT 2');
        self::assertCount(2, $result);
        self::assertStringContainsString('/* block */', $result[0]);
    }

    public function testSplitStatementsWithUnclosedBlockComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1 /* unclosed');
        self::assertCount(1, $result);
        self::assertStringContainsString('/* unclosed', $result[0]);
    }

    public function testSplitStatementsParenthesesNestedDepth(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT (1, (2, 3)); SELECT 4');
        self::assertCount(2, $result);
        self::assertStringContainsString('(1, (2, 3))', $result[0]);
    }

    public function testSplitStatementsClosingParenAtZeroDepth(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1) ; SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsEmptyStatements(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements(';;; ');
        self::assertSame([], $result);
    }

    public function testSplitStatementsSemicolonInsideParens(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT (';')");
        self::assertCount(1, $result);
    }

    public function testParseAssignmentsQuotedColumnName(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET "col name" = 1 WHERE id = 1');
        self::assertArrayHasKey('col name', $result);
        self::assertSame('1', $result['col name']);
    }

    public function testParseAssignmentsBacktickColumnName(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET `col` = 1 WHERE id = 2');
        self::assertArrayHasKey('col', $result);
        self::assertSame('1', $result['col']);
    }

    public function testParseAssignmentsBracketColumnName(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET [col] = 1 WHERE id = 3');
        self::assertArrayHasKey('col', $result);
        self::assertSame('1', $result['col']);
    }

    public function testParseAssignmentsQualifiedColumnName(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET t.name = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $result);
        self::assertSame("'Bob'", $result['name']);
    }

    public function testParseAssignmentsValueWithQuotes(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 'it''s', b = 2 WHERE id = 1");
        self::assertSame("'it''s'", $result['a']);
        self::assertSame('2', $result['b']);
    }

    public function testParseAssignmentsValueWithParens(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = COALESCE(b, 0) WHERE id = 1');
        self::assertSame('COALESCE(b, 0)', $result['a']);
    }

    public function testParseAssignmentsNoSetClause(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t WHERE id = 1');
        self::assertSame([], $result);
    }

    public function testParseValueSetsMultipleRows(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y')");
        self::assertCount(2, $result);
        self::assertSame(['1', "'x'"], $result[0]);
        self::assertSame(['2', "'y'"], $result[1]);
    }

    public function testParseValueSetsNestedParens(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) VALUES (COALESCE(1, 2))');
        self::assertCount(1, $result);
        self::assertSame(['COALESCE(1, 2)'], $result[0]);
    }

    public function testParseValueSetsQuotedStringWithComma(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a, b) VALUES ('a,b', 1)");
        self::assertCount(1, $result);
        self::assertSame(["'a,b'", '1'], $result[0]);
    }

    public function testParseValueSetsEscapedQuotes(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a) VALUES ('it''s')");
        self::assertCount(1, $result);
        self::assertSame(["'it''s'"], $result[0]);
    }

    public function testParseValueSetsDoubleQuotedString(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) VALUES ("hello")');
        self::assertCount(1, $result);
        self::assertSame(['"hello"'], $result[0]);
    }

    public function testParseValueSetsNoValuesKeyword(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) SELECT 1');
        self::assertSame([], $result);
    }

    public function testParseValueSetsEmptyAfterValues(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues('INSERT INTO t (a) VALUES');
        self::assertSame([], $result);
    }

    public function testClassifyWithCteInsertV2(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) INSERT INTO t (a) SELECT * FROM cte'));
    }

    public function testClassifyWithCteUpdateV2(): void
    {
        $parser = new SqliteParser();
        self::assertSame('UPDATE', $parser->classifyStatement('WITH cte AS (SELECT 1) UPDATE t SET a = 1'));
    }

    public function testClassifyWithCteDeleteV2(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DELETE', $parser->classifyStatement('WITH cte AS (SELECT 1) DELETE FROM t'));
    }

    public function testClassifyWithCteReplaceV2(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) REPLACE INTO t (a) VALUES (1)'));
    }

    public function testClassifyWithCteUnsupportedV2(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement('WITH cte AS (SELECT 1) CREATE TABLE t (a INTEGER)'));
    }

    public function testClassifyWithCteNoBodyNoClosure(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement('WITH'));
    }

    public function testClassifyWithCteSingleQuoteInBody(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement("WITH cte AS (SELECT 'val') SELECT * FROM cte"));
    }

    public function testClassifyWithCteNestedParens(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT (1 + (2 * 3))) SELECT * FROM cte'));
    }

    public function testClassifyWithCteEscapedSingleQuote(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement("WITH cte AS (SELECT 'it''s') SELECT * FROM cte"));
    }

    public function testClassifyWithCteEscapedDoubleQuote(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT "a""b") SELECT * FROM cte'));
    }

    public function testClassifyWithCteClosingParenAtZeroDepth(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1)) SELECT * FROM cte'));
    }

    public function testExtractSelectTablesWithJoinV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithAliasV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users AS u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithImplicitAlias(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesMultipleV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users, orders WHERE users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesEmptyFrom(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT 1');
        self::assertSame([], $tables);
    }

    public function testExtractSelectTablesQuotedTable(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM "my table"');
        self::assertNotEmpty($tables);
    }

    public function testExtractSelectTablesWithGroupByV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT name, COUNT(*) FROM users GROUP BY name');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithHavingV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT name, COUNT(*) FROM users HAVING COUNT(*) > 1');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithOrderByV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users ORDER BY name');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithLimitV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users LIMIT 10');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithUnionV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users UNION SELECT * FROM admins');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesLeftJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users LEFT JOIN orders ON users.id = orders.uid');
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesInnerJoin(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users INNER JOIN orders ON 1=1');
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesCrossJoinV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users CROSS JOIN orders');
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesNaturalJoinV2(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users NATURAL JOIN orders');
        self::assertContains('orders', $tables);
    }

    public function testExtractInsertColumnsNoColumnsV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertColumns('INSERT INTO t VALUES (1, 2)');
        self::assertSame([], $result);
    }

    public function testExtractInsertColumnsQuotedV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertColumns('INSERT INTO t ("a", "b") VALUES (1, 2)');
        self::assertSame(['a', 'b'], $result);
    }

    public function testExtractInsertColumnsWithSelect(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertColumns('INSERT INTO t (a, b) SELECT 1, 2');
        self::assertSame(['a', 'b'], $result);
    }

    public function testExtractWhereClauseWithOrderByV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractWhereClause('DELETE FROM t WHERE a > 1 ORDER BY a');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseWithLimitV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractWhereClause('DELETE FROM t WHERE a > 1 LIMIT 5');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseWithGroupByV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractWhereClause('SELECT * FROM t WHERE a > 1 GROUP BY a');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseWithHavingV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractWhereClause('SELECT * FROM t WHERE a > 1 HAVING COUNT(*) > 0');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseNone(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractWhereClause('SELECT * FROM t'));
    }

    public function testExtractOrderByClauseNoneV2(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractOrderByClause('SELECT * FROM t'));
    }

    public function testExtractOrderByClauseWithLimitV2(): void
    {
        $parser = new SqliteParser();
        self::assertSame('a', $parser->extractOrderByClause('SELECT * FROM t ORDER BY a LIMIT 5'));
    }

    public function testExtractLimitClauseNoneV2(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractLimitClause('SELECT * FROM t'));
    }

    public function testExtractLimitClausePresent(): void
    {
        $parser = new SqliteParser();
        self::assertSame('10', $parser->extractLimitClause('SELECT * FROM t LIMIT 10'));
    }

    public function testHasOnConflictTrue(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasOnConflict("INSERT INTO t (a) VALUES (1) ON CONFLICT (a) DO UPDATE SET a = 2"));
    }

    public function testHasOnConflictFalse(): void
    {
        $parser = new SqliteParser();
        self::assertFalse($parser->hasOnConflict("INSERT INTO t (a) VALUES (1)"));
    }

    public function testIsReplaceWithReplace(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isReplace('REPLACE INTO t (a) VALUES (1)'));
    }

    public function testIsReplaceWithInsertOrReplace(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isReplace('INSERT OR REPLACE INTO t (a) VALUES (1)'));
    }

    public function testIsReplaceWithRegularInsert(): void
    {
        $parser = new SqliteParser();
        self::assertFalse($parser->isReplace('INSERT INTO t (a) VALUES (1)'));
    }

    public function testIsInsertIgnoreTrue(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isInsertIgnore('INSERT OR IGNORE INTO t (a) VALUES (1)'));
    }

    public function testIsInsertIgnoreFalse(): void
    {
        $parser = new SqliteParser();
        self::assertFalse($parser->isInsertIgnore('INSERT INTO t (a) VALUES (1)'));
    }

    public function testExtractOnConflictUpdatesV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractOnConflictUpdates("INSERT INTO t (a) VALUES (1) ON CONFLICT (a) DO UPDATE SET b = 2, c = 3");
        self::assertSame(['b' => '2', 'c' => '3'], $result);
    }

    public function testExtractOnConflictUpdatesDoNothingV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractOnConflictUpdates("INSERT INTO t (a) VALUES (1) ON CONFLICT (a) DO NOTHING");
        self::assertSame([], $result);
    }

    public function testHasInsertSelectTrue(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasInsertSelect('INSERT INTO t (a) SELECT 1'));
    }

    public function testHasInsertSelectFalse(): void
    {
        $parser = new SqliteParser();
        self::assertFalse($parser->hasInsertSelect('INSERT INTO t (a) VALUES (1)'));
    }

    public function testExtractInsertSelectPresent(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertSelect('INSERT INTO t (a) SELECT 1 FROM dual');
        self::assertSame('SELECT 1 FROM dual', $result);
    }

    public function testExtractInsertSelectAbsent(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractInsertSelect('INSERT INTO t (a) VALUES (1)'));
    }

    public function testExtractTargetTableSelect(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractTargetTable('SELECT * FROM users'));
    }

    public function testExtractTargetTableUnsupported(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractTargetTable('CREATE INDEX idx ON t (a)'));
    }

    public function testExtractInsertTableQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO "my table" (a) VALUES (1)'));
    }

    public function testExtractUpdateTableWithOrClause(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR ROLLBACK t SET a = 1'));
    }

    public function testExtractUpdateTableWithOrAbort(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR ABORT t SET a = 1'));
    }

    public function testExtractUpdateTableWithOrFail(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR FAIL t SET a = 1'));
    }

    public function testExtractUpdateTableWithOrIgnore(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR IGNORE t SET a = 1'));
    }

    public function testExtractUpdateTableWithOrReplace(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR REPLACE t SET a = 1'));
    }

    public function testExtractDeleteTableQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable('DELETE FROM "my table" WHERE id = 1'));
    }

    public function testExtractCreateTableNameWithIfNotExists(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('CREATE TABLE IF NOT EXISTS t (a INTEGER)'));
    }

    public function testExtractDropTableNameWithIfExists(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('DROP TABLE IF EXISTS t'));
    }

    public function testExtractAlterTableName(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('ALTER TABLE t ADD COLUMN a INTEGER'));
    }

    public function testExtractAlterTableNameQuoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable('ALTER TABLE "my table" ADD COLUMN a INTEGER'));
    }

    public function testExtractReplaceTableName(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('REPLACE INTO t (a) VALUES (1)'));
    }

    public function testExtractReplaceTableNameWithoutInto(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('REPLACE t (a) VALUES (1)'));
    }

    public function testSplitStatementsOnlySemicolons(): void
    {
        $parser = new SqliteParser();
        self::assertSame([], $parser->splitStatements(';'));
    }

    public function testSplitStatementsSingleNoSemicolon(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1');
        self::assertCount(1, $result);
        self::assertSame('SELECT 1', $result[0]);
    }

    public function testParseAssignmentsMultiple(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 1, b = 'x', c = NULL WHERE id = 1");
        self::assertCount(3, $result);
        self::assertSame('1', $result['a']);
        self::assertSame("'x'", $result['b']);
        self::assertSame('NULL', $result['c']);
    }

    public function testParseAssignmentsValueWithNestedParensAndClose(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = (SELECT MAX(b) FROM t2) WHERE id = 1');
        self::assertSame('(SELECT MAX(b) FROM t2)', $result['a']);
    }

    public function testParseAssignmentsDoubleQuotedValueInExpression(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 'x''y' WHERE id = 1");
        self::assertSame("'x''y'", $result['a']);
    }

    public function testExtractTableFromExprEmpty(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM ');
        self::assertSame([], $tables);
    }

    public function testHasInsertSelectWithColumnsAndSelect(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasInsertSelect('INSERT INTO t (a, b) SELECT 1, 2'));
    }

    public function testExtractInsertSelectWithColumnsV2(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertSelect('INSERT INTO t (a, b) SELECT 1, 2 FROM dual');
        self::assertSame('SELECT 1, 2 FROM dual', $result);
    }

    public function testExtractSelectTablesWithQuotedJoinTable(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM t JOIN "other table" ON t.id = "other table".tid');
        self::assertContains('other table', $tables);
    }

    public function testExtractSelectTablesJoinEmptyTable(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM t');
        self::assertContains('t', $tables);
    }

    public function testParseValueSetsEmptyValueInRow(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a, b) VALUES (1, '')");
        self::assertCount(1, $result);
        self::assertSame(['1', "''"], $result[0]);
    }

    public function testClassifyWithCteMultipleCtes(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH a AS (SELECT 1), b AS (SELECT 2) SELECT * FROM a, b'));
    }

    public function testClassifyWithCteKeywordInMiddleOfWord(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function testSplitStatementsPreservesDoubleQuoteContent(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT "col;name" FROM t');
        self::assertCount(1, $result);
        self::assertStringContainsString('"col;name"', $result[0]);
    }

    public function testSplitStatementsPreservesSingleQuoteContent(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 'val;ue' FROM t");
        self::assertCount(1, $result);
        self::assertStringContainsString("'val;ue'", $result[0]);
    }

    public function testParseAssignmentsValueEndingWithCloseParen(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = FUNC(1)');
        self::assertSame('FUNC(1)', $result['a']);
    }

    public function testClassifySelectContainingCreateTableInString(): void
    {
        $parser = new SqliteParser();
        $result = $parser->classifyStatement("SELECT 'CREATE TABLE foo' FROM t");
        self::assertSame('SELECT', $result);
    }

    public function testClassifySelectContainingDropTableInString(): void
    {
        $parser = new SqliteParser();
        $result = $parser->classifyStatement("SELECT 'DROP TABLE foo' FROM t");
        self::assertSame('SELECT', $result);
    }

    public function testClassifySelectContainingAlterTableInString(): void
    {
        $parser = new SqliteParser();
        $result = $parser->classifyStatement("SELECT 'ALTER TABLE foo' FROM t");
        self::assertSame('SELECT', $result);
    }

    public function testClassifyNonKeywordContainingCreateTableReturnsNull(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement("PRAGMA CREATE TABLE foo"));
    }

    public function testClassifyNonKeywordContainingDropTableReturnsNull(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement("PRAGMA DROP TABLE foo"));
    }

    public function testClassifyNonKeywordContainingAlterTableReturnsNull(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement("PRAGMA ALTER TABLE foo"));
    }

    public function testClassifyCreateTableCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('create table foo (id int)'));
        self::assertSame('CREATE_TABLE', $parser->classifyStatement('Create Table foo (id int)'));
    }

    public function testClassifyDropTableCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DROP_TABLE', $parser->classifyStatement('drop table foo'));
        self::assertSame('DROP_TABLE', $parser->classifyStatement('Drop Table foo'));
    }

    public function testClassifyAlterTableCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('alter table foo add column x int'));
        self::assertSame('ALTER_TABLE', $parser->classifyStatement('Alter Table foo add column x int'));
    }

    public function testIsReplaceCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isReplace('insert or replace into t values (1)'));
        self::assertTrue($parser->isReplace('INSERT OR REPLACE INTO t VALUES (1)'));
    }

    public function testIsReplaceNotMatchedInMiddle(): void
    {
        $parser = new SqliteParser();
        self::assertFalse($parser->isReplace("SELECT 'INSERT OR REPLACE' FROM t"));
    }

    public function testIsInsertIgnoreCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isInsertIgnore('insert or ignore into t values (1)'));
        self::assertTrue($parser->isInsertIgnore('INSERT OR IGNORE INTO t VALUES (1)'));
    }

    public function testIsInsertIgnoreNotMatchedInMiddle(): void
    {
        $parser = new SqliteParser();
        self::assertFalse($parser->isInsertIgnore("SELECT 'INSERT OR IGNORE' FROM t"));
    }

    public function testExtractTargetTableReturnsNullForUnclassifiable(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractTargetTable('PRAGMA table_info(users)'));
    }

    public function testExtractInsertValuesReturnsEmptyWithoutValuesKeyword(): void
    {
        $parser = new SqliteParser();
        self::assertSame([], $parser->extractInsertValues('INSERT INTO t SELECT * FROM s'));
    }

    public function testExtractUpdateAssignmentsCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments("update t set name = 'bob' where id = 1");
        self::assertSame('\'bob\'', $result['name']);
    }

    public function testExtractWhereClauseCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractWhereClause('select * from t where id = 1 order by id');
        self::assertSame('id = 1', $result);
    }

    public function testExtractOrderByClauseCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractOrderByClause('select * from t order by name limit 10');
        self::assertSame('name', $result);
    }

    public function testExtractLimitClauseCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractLimitClause('select * from t limit 5');
        self::assertSame('5', $result);
    }

    public function testExtractSelectTablesCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('select * from users where id = 1');
        self::assertContains('users', $tables);
    }

    public function testHasOnConflictCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasOnConflict('insert into t values (1) on conflict do update set a = 1'));
    }

    public function testHasInsertSelectCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasInsertSelect('insert into t (a) select b from s'));
    }

    public function testExtractInsertSelectCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertSelect('insert into t (a) select b from s');
        self::assertNotNull($result);
        self::assertStringContainsString('select', $result);
    }

    public function testExtractInsertSelectReturnsNullWithoutSelect(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractInsertSelect('INSERT INTO t VALUES (1)'));
    }

    public function testStripCommentsRemovesBlockComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->stripComments('SELECT /* comment */ 1');
        self::assertStringNotContainsString('/*', $result);
        self::assertStringNotContainsString('*/', $result);
    }

    public function testStripCommentsRemovesLineComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->stripComments("SELECT 1 -- line comment\nFROM t");
        self::assertStringNotContainsString('--', $result);
    }

    public function testStripCommentsRemovesHashComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->stripComments("SELECT 1 # hash comment\nFROM t");
        self::assertStringNotContainsString('#', $result);
    }

    public function testUnquoteIdentifierDoubleQuote(): void
    {
        $parser = new SqliteParser();
        self::assertSame('table', $parser->unquoteIdentifier('"table"'));
    }

    public function testUnquoteIdentifierBacktick(): void
    {
        $parser = new SqliteParser();
        self::assertSame('table', $parser->unquoteIdentifier('`table`'));
    }

    public function testUnquoteIdentifierBracket(): void
    {
        $parser = new SqliteParser();
        self::assertSame('table', $parser->unquoteIdentifier('[table]'));
    }

    public function testUnquoteIdentifierUnquoted(): void
    {
        $parser = new SqliteParser();
        self::assertSame('table', $parser->unquoteIdentifier('table'));
    }

    public function testUnquoteIdentifierSingleChar(): void
    {
        $parser = new SqliteParser();
        self::assertSame('x', $parser->unquoteIdentifier('x'));
    }

    public function testUnquoteIdentifierEmpty(): void
    {
        $parser = new SqliteParser();
        self::assertSame('', $parser->unquoteIdentifier(''));
    }

    public function testUnquoteIdentifierEscapedDoubleQuote(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my"table', $parser->unquoteIdentifier('"my""table"'));
    }

    public function testUnquoteIdentifierEscapedBacktick(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my`table', $parser->unquoteIdentifier('`my``table`'));
    }

    public function testUnquoteIdentifierOnlyOpenDoubleQuote(): void
    {
        $parser = new SqliteParser();
        self::assertSame('"x', $parser->unquoteIdentifier('"x'));
    }

    public function testUnquoteIdentifierOnlyOpenBacktick(): void
    {
        $parser = new SqliteParser();
        self::assertSame('`x', $parser->unquoteIdentifier('`x'));
    }

    public function testUnquoteIdentifierOnlyOpenBracket(): void
    {
        $parser = new SqliteParser();
        self::assertSame('[x', $parser->unquoteIdentifier('[x'));
    }

    public function testExtractTableFromExprAlias(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users AS u');
        self::assertContains('users', $tables);
    }

    public function testExtractTableFromExprSpaceAlias(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users u');
        self::assertContains('users', $tables);
    }

    public function testExtractTableFromExprEmptyEntry(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT 1');
        self::assertSame([], $tables);
    }

    public function testSplitStatementsDoubleQuoteEscape(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT "col""name"; SELECT 1');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsSingleQuoteEscape(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 'it''s'; SELECT 1");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsLineComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements("SELECT 1; -- comment\nSELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsBlockComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1; /* block */ SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsUnclosedBlockComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1 /* unclosed block');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsUnclosedLineComment(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT 1 -- end');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsParenthesizedSubquery(): void
    {
        $parser = new SqliteParser();
        $result = $parser->splitStatements('SELECT (SELECT 1; SELECT 2)');
        self::assertCount(1, $result);
    }

    public function testParseAssignmentsTablePrefixed(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET t.name = 1');
        self::assertSame('1', $result['name']);
    }

    public function testParseAssignmentsEmptyColumnSkipped(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET = 1');
        self::assertSame([], $result);
    }

    public function testParseAssignmentsQuotedStringValue(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 'it''s', b = 2");
        self::assertSame("'it''s'", $result['a']);
        self::assertSame('2', $result['b']);
    }

    public function testParseAssignmentsParenthesizedValue(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = (SELECT 1), b = 2');
        self::assertSame('(SELECT 1)', $result['a']);
        self::assertSame('2', $result['b']);
    }

    public function testExtractInsertColumnsFromReplace(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertColumns('REPLACE INTO t (a, b) VALUES (1, 2)');
        self::assertSame(['a', 'b'], $result);
    }

    public function testExtractInsertTableFromReplace(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractTargetTable('REPLACE INTO t VALUES (1)');
        self::assertSame('t', $result);
    }

    public function testExtractInsertTableFromReplaceNoInto(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractTargetTable('REPLACE t VALUES (1)');
        self::assertSame('t', $result);
    }

    public function testExtractOnConflictUpdatesCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractOnConflictUpdates('insert into t values (1) on conflict (id) do update set a = excluded.a');
        self::assertArrayHasKey('a', $result);
    }

    public function testClassifyWithSelectCte(): void
    {
        $parser = new SqliteParser();
        self::assertSame('SELECT', $parser->classifyStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function testClassifyWithInsertCte(): void
    {
        $parser = new SqliteParser();
        self::assertSame('INSERT', $parser->classifyStatement('WITH cte AS (SELECT 1) INSERT INTO t SELECT * FROM cte'));
    }

    public function testClassifyWithUpdateCte(): void
    {
        $parser = new SqliteParser();
        self::assertSame('UPDATE', $parser->classifyStatement("WITH cte AS (SELECT 1) UPDATE t SET a = 1"));
    }

    public function testClassifyWithDeleteCte(): void
    {
        $parser = new SqliteParser();
        self::assertSame('DELETE', $parser->classifyStatement('WITH cte AS (SELECT 1) DELETE FROM t'));
    }

    public function testClassifyWithCteQuotedString(): void
    {
        $parser = new SqliteParser();
        $result = $parser->classifyStatement("WITH cte AS (SELECT ')') SELECT * FROM cte");
        self::assertSame('SELECT', $result);
    }

    public function testClassifyWithCteDoubleQuotedIdentifier(): void
    {
        $parser = new SqliteParser();
        $result = $parser->classifyStatement('WITH cte AS (SELECT "col)") SELECT * FROM cte');
        self::assertSame('SELECT', $result);
    }

    public function testParseValueSetsMultiple(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues("INSERT INTO t VALUES (1, 'a'), (2, 'b')");
        self::assertCount(2, $result);
    }

    public function testParseValueSetsWithQuotedParen(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues("INSERT INTO t VALUES ('(1)', 'a')");
        self::assertCount(1, $result);
        self::assertSame(["'(1)'", "'a'"], $result[0]);
    }

    public function testExtractSelectTablesFromMultipleTables(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users, orders WHERE users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithJoinSkipsJoined(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
    }

    public function testExtractInsertTableCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('insert into t values (1)'));
    }

    public function testExtractDeleteTableCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('delete from t where id = 1'));
    }

    public function testExtractCreateTableName(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('CREATE TABLE users (id INTEGER)'));
    }

    public function testExtractDropTableName(): void
    {
        $parser = new SqliteParser();
        self::assertSame('users', $parser->extractTargetTable('DROP TABLE users'));
    }

    public function testClassifyCommentOnlyReturnsNull(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement('-- just a comment'));
    }

    public function testClassifyBlockCommentOnlyReturnsNull(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->classifyStatement('/* block comment only */'));
    }

    public function testExtractUpdateTableCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable("update t set a = 1"));
    }

    public function testExtractInsertTableWithoutIntoReturnsNull(): void
    {
        $parser = new SqliteParser();
        self::assertNull($parser->extractTargetTable('INSERT (a) VALUES (1)'));
    }

    public function testParseAssignmentsMultipleCommaValues(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 1, b = 'two', c = 3");
        self::assertSame('1', $result['a']);
        self::assertSame("'two'", $result['b']);
        self::assertSame('3', $result['c']);
    }

    public function testExtractInsertTableQuotedIdentifier(): void
    {
        $parser = new SqliteParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO "my table" VALUES (1)'));
    }

    public function testExtractReplaceTableCaseInsensitive(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('replace into t values (1)'));
    }

    public function testExtractSelectTablesReturnsEmptyForSubqueryOnly(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables('SELECT * FROM (SELECT 1)');
        self::assertNotEmpty($tables);
    }

    public function testExtractSelectTablesMultiline(): void
    {
        $parser = new SqliteParser();
        $tables = $parser->extractSelectTables("SELECT * FROM users,\norders WHERE id = 1");
        self::assertContains('users', $tables);
    }

    public function testExtractWhereClauseMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractWhereClause("SELECT * FROM t WHERE id = 1\nAND name = 'x' ORDER BY id");
        self::assertNotNull($result);
        self::assertStringContainsString('name', $result);
    }

    public function testExtractOrderByClauseMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractOrderByClause("SELECT * FROM t ORDER BY name,\nid LIMIT 10");
        self::assertNotNull($result);
        self::assertStringContainsString('id', $result);
    }

    public function testExtractLimitClauseMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractLimitClause("SELECT * FROM t LIMIT 5\nOFFSET 10");
        self::assertNotNull($result);
        self::assertStringContainsString('5', $result);
    }

    public function testExtractUpdateAssignmentsMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET name = 'bob',\nemail = 'x' WHERE id = 1");
        self::assertSame("'bob'", $result['name']);
        self::assertSame("'x'", $result['email']);
    }

    public function testExtractOnConflictUpdatesMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractOnConflictUpdates("INSERT INTO t VALUES (1) ON CONFLICT (id) DO UPDATE SET name = excluded.name,\nemail = excluded.email");
        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
    }

    public function testExtractInsertSelectMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertSelect("INSERT INTO t (a) SELECT b\nFROM s");
        self::assertNotNull($result);
        self::assertStringContainsString('SELECT', $result);
    }

    public function testHasInsertSelectMultiline(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasInsertSelect("INSERT INTO t\n(a)\nSELECT b FROM s"));
    }

    public function testHasOnConflictMultiline(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->hasOnConflict("INSERT INTO t VALUES (1)\nON CONFLICT\n(id) DO UPDATE SET a = 1"));
    }

    public function testExtractInsertColumnsMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertColumns("INSERT INTO t\n(a, b)\nVALUES (1, 2)");
        self::assertSame(['a', 'b'], $result);
    }

    public function testExtractInsertValuesMultiline(): void
    {
        $parser = new SqliteParser();
        $result = $parser->extractInsertValues("INSERT INTO t (a)\nVALUES\n(1)");
        self::assertCount(1, $result);
    }

    public function testIsReplaceCaseInsensitiveOrReplace(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isReplace('Insert Or Replace INTO t VALUES (1)'));
    }

    public function testIsInsertIgnoreMixedCase(): void
    {
        $parser = new SqliteParser();
        self::assertTrue($parser->isInsertIgnore('Insert Or Ignore INTO t VALUES (1)'));
    }

    public function testExtractInsertTableFromReplaceWithoutInto(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('REPLACE t (a) VALUES (1)'));
    }

    public function testExtractReplaceCaseInsensitiveWithoutInto(): void
    {
        $parser = new SqliteParser();
        self::assertSame('t', $parser->extractTargetTable('replace t values (1)'));
    }
}
