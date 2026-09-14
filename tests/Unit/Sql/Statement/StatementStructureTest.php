<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Statement;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Statement\StatementStructure;

#[CoversClass(StatementStructure::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\RelationSourceParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class StatementStructureTest extends TestCase
{
    public function testSplitStatementsSplitSingleStatement(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT * FROM users');
        self::assertCount(1, $result);
        self::assertSame('SELECT * FROM users', $result[0]);
    }

    public function testSplitStatementsSplitMultipleStatements(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1; SELECT 2');
        self::assertCount(2, $result);
        self::assertSame('SELECT 1', $result[0]);
        self::assertSame('SELECT 2', $result[1]);
    }

    public function testSplitStatementsIgnoresSemicolonInString(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 'a;b' FROM t");
        self::assertCount(1, $result);
    }

    public function testSplitStatementsIgnoresSemicolonInParentheses(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT (1;2) FROM t');
        self::assertCount(1, $result);
    }

    public function testExtractSelectTables(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesDeduplicatesAndReindexesInFirstSeenOrder(): void
    {
        $parser = new StatementStructure();

        self::assertSame(
            ['users', 'orders'],
            $parser->extractSelectTables(
                'SELECT * FROM users UNION SELECT * FROM users UNION SELECT * FROM orders'
            ),
        );
    }

    public function testExtractSelectTablesExtractSelectTableAfterBlockComment(): void
    {
        $parser = new StatementStructure();
        self::assertSame(['my_table'], $parser->extractSelectTables('SELECT * FROM /* table */ my_table'));
    }

    public function testExtractSelectTablesExtractSelectTableIgnoresKeywordsInsideLineComment(): void
    {
        $parser = new StatementStructure();
        $sql = "-- SELECT * FROM other_table WHERE DELETE UPDATE INSERT\nSELECT * FROM items ORDER BY id";
        self::assertSame(['items'], $parser->extractSelectTables($sql));
    }

    public function testExtractSelectTablesExtractSelectTableIgnoresFromInsideStringLiteral(): void
    {
        $parser = new StatementStructure();
        $sql = "SELECT id, 'from other_table' AS label FROM items LIMIT 1";
        self::assertSame(['items'], $parser->extractSelectTables($sql));
    }

    public function testExtractSelectTablesExtractSelectTableIgnoresJoinInsideStringLiteral(): void
    {
        $parser = new StatementStructure();
        $sql = "SELECT id, 'JOIN other_table' AS label FROM items LIMIT 1";
        self::assertSame(['items'], $parser->extractSelectTables($sql));
    }

    public function testSplitStatementsIgnoresSemicolonInDoubleQuote(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT "a;b" FROM t');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsHandlesLineComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 1; -- comment\nSELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesBlockComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1; /* comment */ SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesUnclosedBlockComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1 /* unclosed comment');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsHandlesLineCommentAtEnd(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1 -- trailing comment');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsHandlesEscapedSingleQuote(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 'it''s'; SELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesEscapedDoubleQuote(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT "col""name"; SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsHandlesNestedParens(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT (1 + (2 + 3)); SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsEmptyInput(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('');
        self::assertSame([], $result);
    }

    public function testSplitStatementsTrailingSemicolon(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1;');
        self::assertCount(1, $result);
    }

    public function testExtractSelectTablesWithAlias(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithAsAlias(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users AS u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesMultiple(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users, orders');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithWhere(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users WHERE id = 1');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithGroupBy(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users GROUP BY name');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithOrderBy(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users ORDER BY id');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithLimit(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users LIMIT 10');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesQuoted(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM "users"');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesNoFrom(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT 1');
        self::assertSame([], $tables);
    }

    public function testExtractSelectTablesWithLeftJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithInnerJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users INNER JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithHaving(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users HAVING count(*) > 1');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithUnion(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users UNION SELECT * FROM admins');
        self::assertContains('users', $tables);
    }

    public function testSplitStatementsEmpty(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('  ;  ;  ');
        self::assertSame([], $result);
    }

    public function testExtractSelectTablesEmptyExpr(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM , users');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesLowercase(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('select * from users where id = 1');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithJoinLowercase(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('select * from users join orders on users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithGroupByLowercase(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('select * from users group by name');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithOrderByLowercase(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('select * from users order by id');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesWithLimitLowercase(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('select * from users limit 10');
        self::assertSame(['users'], $tables);
    }

    public function testExtractSelectTablesLeftJoinLowercase(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('select * from users left join orders on users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesCrossJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users CROSS JOIN orders');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesNaturalJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users NATURAL JOIN orders');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesRightJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users RIGHT JOIN orders ON 1=1');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testSplitStatementsWithEscapedSingleQuotes(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 'it''s'; SELECT 2");
        self::assertCount(2, $result);
        self::assertStringContainsString("it''s", $result[0]);
        self::assertSame('SELECT 2', $result[1]);
    }

    public function testSplitStatementsWithEscapedDoubleQuotes(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT "a""b"; SELECT 2');
        self::assertCount(2, $result);
        self::assertStringContainsString('a""b', $result[0]);
        self::assertSame('SELECT 2', $result[1]);
    }

    public function testSplitStatementsWithLineComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 1 -- comment\n; SELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsWithLineCommentNoNewline(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1 -- comment at end');
        self::assertCount(1, $result);
        self::assertStringContainsString('SELECT 1', $result[0]);
        self::assertStringContainsString('-- comment at end', $result[0]);
    }

    public function testSplitStatementsWithBlockComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT /* block */ 1; SELECT 2');
        self::assertCount(2, $result);
        self::assertStringContainsString('/* block */', $result[0]);
    }

    public function testSplitStatementsWithUnclosedBlockComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1 /* unclosed');
        self::assertCount(1, $result);
        self::assertStringContainsString('/* unclosed', $result[0]);
    }

    public function testSplitStatementsParenthesesNestedDepth(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT (1, (2, 3)); SELECT 4');
        self::assertCount(2, $result);
        self::assertStringContainsString('(1, (2, 3))', $result[0]);
    }

    public function testSplitStatementsClosingParenAtZeroDepth(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1) ; SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsEmptyStatements(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements(';;; ');
        self::assertSame([], $result);
    }

    public function testSplitStatementsSemicolonInsideParens(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT (';')");
        self::assertCount(1, $result);
    }

    public function testExtractSelectTablesWithJoinV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithAliasV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users AS u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithImplicitAlias(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesMultipleV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users, orders WHERE users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesEmptyFrom(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT 1');
        self::assertSame([], $tables);
    }

    public function testExtractSelectTablesQuotedTable(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM "my table"');
        self::assertNotEmpty($tables);
    }

    public function testExtractSelectTablesWithGroupByV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT name, COUNT(*) FROM users GROUP BY name');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithHavingV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT name, COUNT(*) FROM users HAVING COUNT(*) > 1');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithOrderByV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users ORDER BY name');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithLimitV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users LIMIT 10');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesWithUnionV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users UNION SELECT * FROM admins');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesLeftJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users LEFT JOIN orders ON users.id = orders.uid');
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesInnerJoin(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users INNER JOIN orders ON 1=1');
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesCrossJoinV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users CROSS JOIN orders');
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesNaturalJoinV2(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users NATURAL JOIN orders');
        self::assertContains('orders', $tables);
    }

    public function testSplitStatementsOnlySemicolons(): void
    {
        $parser = new StatementStructure();
        self::assertSame([], $parser->splitStatements(';'));
    }

    public function testSplitStatementsSingleNoSemicolon(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1');
        self::assertCount(1, $result);
        self::assertSame('SELECT 1', $result[0]);
    }

    public function testExtractSelectTablesExtractTableFromExprEmpty(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM ');
        self::assertSame([], $tables);
    }

    public function testExtractSelectTablesWithQuotedJoinTable(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM t JOIN "other table" ON t.id = "other table".tid');
        self::assertContains('other table', $tables);
    }

    public function testExtractSelectTablesJoinEmptyTable(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM t');
        self::assertContains('t', $tables);
    }

    public function testSplitStatementsPreservesDoubleQuoteContent(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT "col;name" FROM t');
        self::assertCount(1, $result);
        self::assertStringContainsString('"col;name"', $result[0]);
    }

    public function testSplitStatementsPreservesSingleQuoteContent(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 'val;ue' FROM t");
        self::assertCount(1, $result);
        self::assertStringContainsString("'val;ue'", $result[0]);
    }

    public function testExtractSelectTablesCaseInsensitive(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('select * from users where id = 1');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesExtractTableFromExprAlias(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users AS u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesExtractTableFromExprSpaceAlias(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users u');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesExtractTableFromExprEmptyEntry(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT 1');
        self::assertSame([], $tables);
    }

    public function testSplitStatementsDoubleQuoteEscape(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT "col""name"; SELECT 1');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsSingleQuoteEscape(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 'it''s'; SELECT 1");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsLineComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements("SELECT 1; -- comment\nSELECT 2");
        self::assertCount(2, $result);
    }

    public function testSplitStatementsBlockComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1; /* block */ SELECT 2');
        self::assertCount(2, $result);
    }

    public function testSplitStatementsUnclosedBlockComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1 /* unclosed block');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsUnclosedLineComment(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT 1 -- end');
        self::assertCount(1, $result);
    }

    public function testSplitStatementsParenthesizedSubquery(): void
    {
        $parser = new StatementStructure();
        $result = $parser->splitStatements('SELECT (SELECT 1; SELECT 2)');
        self::assertCount(1, $result);
    }

    public function testExtractSelectTablesFromMultipleTables(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users, orders WHERE users.id = orders.user_id');
        self::assertContains('users', $tables);
        self::assertContains('orders', $tables);
    }

    public function testExtractSelectTablesWithJoinSkipsJoined(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesReturnsEmptyForSubqueryOnly(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables('SELECT * FROM (SELECT 1)');
        self::assertSame([], $tables);
    }

    public function testExtractSelectTablesMultiline(): void
    {
        $parser = new StatementStructure();
        $tables = $parser->extractSelectTables("SELECT * FROM users,\norders WHERE id = 1");
        self::assertContains('users', $tables);
    }

    public function testExtractSelectTablesSelectTablesComeFromSelectScopesOnly(): void
    {
        $parser = new StatementStructure();
        $sql = 'SELECT printf(\'from %s\', name) FROM events WHERE id IN (SELECT event_id FROM archived_events)';

        self::assertSame(['events', 'archived_events'], $parser->extractSelectTables($sql));
    }

    public function testStatementTailFindsMainStatementAfterWith(): void
    {
        $parser = new StatementStructure();
        self::assertSame('UPDATE users SET id = 2', $parser->statementTail('WITH t AS (SELECT 1) UPDATE users SET id = 2', ['UPDATE']));
        self::assertSame('SELECT 1', $parser->statementTail('/* hint */ SELECT 1', ['UPDATE']));
    }

}
