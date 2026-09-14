<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Dml\Expression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\AssignmentParser;

#[CoversClass(AssignmentParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\AssignmentColumnParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class AssignmentParserTest extends TestCase
{
    public function testExtractUpdateAssignments(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE users SET name = 'Bob', age = 30 WHERE id = 1");
        self::assertSame("'Bob'", $assignments['name']);
        self::assertSame('30', $assignments['age']);
    }

    public function testExtractOnConflictUpdates(): void
    {
        $parser = new AssignmentParser();
        $updates = $parser->extractOnConflictUpdates(
            'INSERT INTO users (id, name) VALUES (1, "Alice") ON CONFLICT (id) DO UPDATE SET name = excluded.name'
        );
        self::assertArrayHasKey('name', $updates);
        self::assertSame('excluded.name', $updates['name']);
    }

    public function testExtractOnConflictUpdatesExtractOnConflictStopsBeforeReturningWithoutWhere(): void
    {
        $parser = new AssignmentParser();

        self::assertSame(
            ['name' => 'excluded.name'],
            $parser->extractOnConflictUpdates(
                'INSERT INTO users (id, name) VALUES (1, "Alice") ON CONFLICT (id) DO UPDATE SET name = excluded.name RETURNING id'
            ),
        );
    }

    public function testExtractOnConflictUpdatesExtractOnConflictRejectsNonUpdateActionContainingSet(): void
    {
        $parser = new AssignmentParser();

        self::assertSame(
            [],
            $parser->extractOnConflictUpdates(
                'INSERT INTO users (id, name) VALUES (1, "Alice") ON CONFLICT (id) DO NOTHING SET name = excluded.name'
            ),
        );
    }

    public function testExtractUpdateAssignmentsQuotedColumn(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET "name" = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
        self::assertSame("'Bob'", $assignments['name']);
    }

    public function testExtractUpdateAssignmentsBacktickColumn(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET `name` = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractUpdateAssignmentsBracketColumn(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET [name] = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractUpdateAssignmentsTableQualified(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE users SET users.name = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractUpdateAssignmentsWithFunction(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE t SET x = COALESCE(a, 'b') WHERE id = 1");
        self::assertArrayHasKey('x', $assignments);
        self::assertStringContainsString('COALESCE', $assignments['x']);
    }

    public function testExtractUpdateAssignmentsEmpty(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('SELECT * FROM users');
        self::assertSame([], $assignments);
    }

    public function testExtractUpdateAssignmentsWithOrderBy(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE t SET x = 1 ORDER BY id');
        self::assertArrayHasKey('x', $assignments);
        self::assertSame('1', $assignments['x']);
    }

    public function testExtractUpdateAssignmentsWithLimit(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('UPDATE t SET x = 1 LIMIT 5');
        self::assertArrayHasKey('x', $assignments);
        self::assertSame('1', $assignments['x']);
    }

    public function testExtractOnConflictUpdatesDoNothing(): void
    {
        $parser = new AssignmentParser();
        $updates = $parser->extractOnConflictUpdates('INSERT INTO t (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
        self::assertSame([], $updates);
    }

    public function testExtractOnConflictUpdatesNoConflict(): void
    {
        $parser = new AssignmentParser();
        $updates = $parser->extractOnConflictUpdates('INSERT INTO t (id) VALUES (1)');
        self::assertSame([], $updates);
    }

    public function testExtractOnConflictUpdatesMultiple(): void
    {
        $parser = new AssignmentParser();
        $updates = $parser->extractOnConflictUpdates(
            'INSERT INTO t (id, name, age) VALUES (1, \'a\', 2) ON CONFLICT (id) DO UPDATE SET name = excluded.name, age = excluded.age'
        );
        self::assertArrayHasKey('name', $updates);
        self::assertArrayHasKey('age', $updates);
        self::assertSame('excluded.name', $updates['name']);
        self::assertSame('excluded.age', $updates['age']);
    }

    public function testExtractUpdateAssignmentsWithQuotedValue(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments("UPDATE t SET name = 'it''s cool' WHERE id = 1");
        self::assertArrayHasKey('name', $assignments);
        self::assertSame("'it''s cool'", $assignments['name']);
    }

    public function testExtractUpdateAssignmentsLowercase(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments("update users set name = 'Bob' where id = 1");
        self::assertArrayHasKey('name', $assignments);
    }

    public function testExtractOnConflictUpdatesLowercase(): void
    {
        $parser = new AssignmentParser();
        $updates = $parser->extractOnConflictUpdates(
            "insert into t (id, name) values (1, 'a') on conflict (id) do update set name = excluded.name"
        );
        self::assertArrayHasKey('name', $updates);
    }

    public function testExtractUpdateAssignmentsWithLimitLowercase(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('update t set x = 1 limit 5');
        self::assertArrayHasKey('x', $assignments);
    }

    public function testExtractUpdateAssignmentsWithOrderByLowercase(): void
    {
        $parser = new AssignmentParser();
        $assignments = $parser->extractUpdateAssignments('update t set x = 1 order by id');
        self::assertArrayHasKey('x', $assignments);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsQuotedColumnName(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET "col name" = 1 WHERE id = 1');
        self::assertArrayHasKey('col name', $result);
        self::assertSame('1', $result['col name']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsBacktickColumnName(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET `col` = 1 WHERE id = 2');
        self::assertArrayHasKey('col', $result);
        self::assertSame('1', $result['col']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsBracketColumnName(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET [col] = 1 WHERE id = 3');
        self::assertArrayHasKey('col', $result);
        self::assertSame('1', $result['col']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsQualifiedColumnName(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET t.name = \'Bob\' WHERE id = 1');
        self::assertArrayHasKey('name', $result);
        self::assertSame("'Bob'", $result['name']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsValueWithQuotes(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 'it''s', b = 2 WHERE id = 1");
        self::assertSame("'it''s'", $result['a']);
        self::assertSame('2', $result['b']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsValueWithParens(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = COALESCE(b, 0) WHERE id = 1');
        self::assertSame('COALESCE(b, 0)', $result['a']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsNoSetClause(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t WHERE id = 1');
        self::assertSame([], $result);
    }

    public function testExtractOnConflictUpdatesV2(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractOnConflictUpdates('INSERT INTO t (a) VALUES (1) ON CONFLICT (a) DO UPDATE SET b = 2, c = 3');
        self::assertSame(['b' => '2', 'c' => '3'], $result);
    }

    public function testExtractOnConflictUpdatesDoNothingV2(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractOnConflictUpdates('INSERT INTO t (a) VALUES (1) ON CONFLICT (a) DO NOTHING');
        self::assertSame([], $result);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsMultiple(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 1, b = 'x', c = NULL WHERE id = 1");
        self::assertCount(3, $result);
        self::assertSame('1', $result['a']);
        self::assertSame("'x'", $result['b']);
        self::assertSame('NULL', $result['c']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsValueWithNestedParensAndClose(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = (SELECT MAX(b) FROM t2) WHERE id = 1');
        self::assertSame('(SELECT MAX(b) FROM t2)', $result['a']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsDoubleQuotedValueInExpression(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 'x''y' WHERE id = 1");
        self::assertSame("'x''y'", $result['a']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsValueEndingWithCloseParen(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = FUNC(1)');
        self::assertSame('FUNC(1)', $result['a']);
    }

    public function testExtractUpdateAssignmentsCaseInsensitive(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments("update t set name = 'bob' where id = 1");
        self::assertSame('\'bob\'', $result['name']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsTablePrefixed(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET t.name = 1');
        self::assertSame('1', $result['name']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsEmptyColumnSkipped(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET = 1');
        self::assertSame([], $result);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsQuotedStringValue(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 'it''s', b = 2");
        self::assertSame("'it''s'", $result['a']);
        self::assertSame('2', $result['b']);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsParenthesizedValue(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments('UPDATE t SET a = (SELECT 1), b = 2');
        self::assertSame('(SELECT 1)', $result['a']);
        self::assertSame('2', $result['b']);
    }

    public function testExtractOnConflictUpdatesCaseInsensitive(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractOnConflictUpdates('insert into t values (1) on conflict (id) do update set a = excluded.a');
        self::assertArrayHasKey('a', $result);
    }

    public function testExtractUpdateAssignmentsParseAssignmentsMultipleCommaValues(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET a = 1, b = 'two', c = 3");
        self::assertSame('1', $result['a']);
        self::assertSame("'two'", $result['b']);
        self::assertSame('3', $result['c']);
    }

    public function testExtractUpdateAssignmentsMultiline(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractUpdateAssignments("UPDATE t SET name = 'bob',\nemail = 'x' WHERE id = 1");
        self::assertSame("'bob'", $result['name']);
        self::assertSame("'x'", $result['email']);
    }

    public function testExtractOnConflictUpdatesMultiline(): void
    {
        $parser = new AssignmentParser();
        $result = $parser->extractOnConflictUpdates("INSERT INTO t VALUES (1) ON CONFLICT (id) DO UPDATE SET name = excluded.name,\nemail = excluded.email");
        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('email', $result);
    }

    public function testExtractOnConflictUpdatesConflictAssignmentsIgnoreNestedWhereAndReturningKeywords(): void
    {
        $parser = new AssignmentParser();
        $sql = 'INSERT INTO target (id, value) VALUES (1, 2) ON CONFLICT (id) DO UPDATE SET value = (SELECT value FROM source WHERE source.id = excluded.id), note = \'returning where\' WHERE target.active RETURNING *';

        self::assertSame([
            'value' => '(SELECT value FROM source WHERE source.id = excluded.id)',
            'note' => "'returning where'",
        ], $parser->extractOnConflictUpdates($sql));
    }

    public function testParseAssignmentsRetainsNestedCommas(): void
    {
        self::assertSame(['name' => "coalesce('a,b', name)", 'id' => 'id + 1'], (new AssignmentParser())->parseAssignments("name = coalesce('a,b', name), id = id + 1"));
    }

}
