<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteLexerProfile;
use ZtdQuery\Platform\Sqlite\SqliteSelectRelationParser;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;

#[CoversClass(SqliteSelectRelationParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class SqliteSelectRelationParserTest extends TestCase
{
    public function testFindsSqliteQuotedAndNestedRelations(): void
    {
        $sql = 'SELECT * FROM (SELECT * FROM [catalog].[a]]b] UNION SELECT * FROM `archived`) p JOIN json_each(p.data) j ON TRUE JOIN audit_log a ON TRUE';

        self::assertSame(
            ['audit_log', 'a]b', 'archived'],
            (new SqliteSelectRelationParser())->tableNames($sql),
        );
    }

    public function testFromClausesUseSqliteBoundaries(): void
    {
        $parser = new SqliteSelectRelationParser();

        self::assertSame(
            ['users', 'archived'],
            $parser->fromClauses('SELECT * FROM users LIMIT 1; SELECT * FROM archived RETURNING id'),
        );
    }

    public function testReferencesPreserveOffsetsAndUnqualifyOnlyTargets(): void
    {
        $parser = new SqliteSelectRelationParser();
        $sql = 'SELECT * FROM main.[users] u JOIN logs.audit_log a ON TRUE';

        self::assertSame(['users', 'audit_log'], array_column($parser->references($sql), 'name'));
        self::assertSame(
            'SELECT * FROM [users] u JOIN logs.audit_log a ON TRUE',
            $parser->unqualify($sql, ['users']),
        );
    }

    public function testReferencesExposeExactQualifiedIdentifierOffsets(): void
    {
        $parser = new SqliteSelectRelationParser();
        $sql = 'SELECT * FROM main.[a]]b] a JOIN users u ON TRUE JOIN logs.orders o ON TRUE';

        self::assertSame([
            [
                'name' => 'a]b',
                'start' => 14,
                'unqualifiedStart' => 19,
                'end' => 25,
            ],
            [
                'name' => 'users',
                'start' => 33,
                'unqualifiedStart' => 33,
                'end' => 38,
            ],
            [
                'name' => 'orders',
                'start' => 54,
                'unqualifiedStart' => 59,
                'end' => 65,
            ],
        ], $parser->references($sql));
    }

    public function testUnqualifiesMultipleTargetsCaseInsensitivelyFromRightToLeft(): void
    {
        $sql = 'SELECT * FROM users u JOIN main.Users u2 ON TRUE JOIN logs.orders o ON TRUE';

        self::assertSame(
            'SELECT * FROM users u JOIN Users u2 ON TRUE JOIN orders o ON TRUE',
            (new SqliteSelectRelationParser())->unqualify($sql, ['USERS', 'ORDERS']),
        );
    }

    public function testKeepsOneCaseInsensitiveRelationName(): void
    {
        self::assertSame(
            ['Users'],
            (new SqliteSelectRelationParser())->tableNames('SELECT * FROM Users JOIN users ON TRUE'),
        );
    }

    public function testSetOperatorsAndRepeatedFromCloseTheCurrentSelectScope(): void
    {
        $parser = new SqliteSelectRelationParser();
        $sql = 'FROM orphan; SELECT 1 UNION FROM union_hidden; SELECT 1 INTERSECT FROM intersect_hidden; SELECT 1 EXCEPT FROM except_hidden; SELECT * FROM visible';

        self::assertSame(['visible'], $parser->fromClauses($sql));
        self::assertSame(['users'], $parser->tableNames('SELECT * FROM users FROM ignored'));
        self::assertSame(['users FROM ignored'], $parser->fromClauses('SELECT * FROM users FROM ignored'));
        self::assertSame([], $parser->tableNames('FROM orphan'));
    }

    public function testFindsParenthesizedJoinedRelationsButNotDerivedProducers(): void
    {
        $parser = new SqliteSelectRelationParser();
        $groupedSql = 'SELECT * FROM (main.users JOIN logs.orders ON TRUE) grouped, extra';

        self::assertSame(['users', 'orders', 'extra'], $parser->tableNames($groupedSql));
        self::assertSame([
            ['name' => 'users', 'start' => 15, 'unqualifiedStart' => 20, 'end' => 25],
            ['name' => 'orders', 'start' => 31, 'unqualifiedStart' => 36, 'end' => 42],
            ['name' => 'extra', 'start' => 61, 'unqualifiedStart' => 61, 'end' => 66],
        ], $parser->references($groupedSql));
        self::assertSame([], $parser->tableNames('SELECT * FROM () empty_group'));
        self::assertSame(['extra'], $parser->tableNames('SELECT * FROM (), extra'));
        self::assertSame([], $parser->tableNames('SELECT * FROM (VALUES rogue, leaked) produced'));
        self::assertSame([], $parser->tableNames('SELECT * FROM (WITH rogue, leaked) produced'));
        self::assertSame(
            ['users'],
            $parser->tableNames('SELECT * FROM (SELECT id, name FROM main.users) produced'),
        );
        self::assertSame(['users', 'orders'], $parser->tableNames('SELECT * FROM users, orders'));
    }

    public function testNestedSelectFromClausesStopAtTheirOwnScopeBoundary(): void
    {
        $parser = new SqliteSelectRelationParser();

        self::assertSame(
            ['(SELECT * FROM inner_table) derived', 'inner_table'],
            $parser->fromClauses('SELECT * FROM (SELECT * FROM inner_table) derived WHERE active = 1'),
        );
        self::assertSame(
            ['(SELECT * FROM inner_table WHERE id = 1) derived', 'inner_table'],
            $parser->fromClauses(
                'SELECT * FROM (SELECT * FROM inner_table WHERE id = 1) derived WHERE active = 1',
            ),
        );
    }

    public function testReferencesDoNotReadPastTheFromClauseBoundary(): void
    {
        self::assertSame(
            ['users'],
            (new SqliteSelectRelationParser())->tableNames('SELECT * FROM users WHERE TRUE JOIN leaked ON TRUE'),
        );
    }

    public function testParenthesizedRelationOffsetsDoNotIncludeFollowingAlias(): void
    {
        $sql = 'SELECT * FROM          (main.users) grouped, extra';

        self::assertSame([
            ['name' => 'users', 'start' => 24, 'unqualifiedStart' => 29, 'end' => 34],
            ['name' => 'extra', 'start' => 45, 'unqualifiedStart' => 45, 'end' => 50],
        ], (new SqliteSelectRelationParser())->references($sql));
    }

    public function testDoesNotTreatQuotedFunctionNameAsRelation(): void
    {
        $parser = new SqliteSelectRelationParser();

        self::assertSame([], $parser->tableNames('SELECT * FROM `json_each`(payload) j'));
        self::assertSame([], $parser->tableNames('SELECT * FROM [json_each](payload) j'));
    }

    public function testUnescapesDoubledSqliteIdentifierQuotes(): void
    {
        self::assertSame(
            ['a"b', 'c`d'],
            (new SqliteSelectRelationParser())->tableNames(
                'SELECT * FROM "a""b" JOIN `c``d` ON TRUE',
            ),
        );
    }

    /** @param list<string> $expected */
    #[DataProvider('providerSqliteFromTerminators')]
    public function testStopsFromClauseAtEverySqliteBoundary(string $suffix, array $expected): void
    {
        self::assertSame(
            $expected,
            (new SqliteSelectRelationParser())->fromClauses('SELECT * FROM users ' . $suffix),
        );
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function providerSqliteFromTerminators(): iterable
    {
        yield 'where' => ['WHERE active = 1', ['users']];
        yield 'group by' => ['GROUP BY id', ['users']];
        yield 'having' => ['HAVING count(*) > 1', ['users']];
        yield 'window' => ['WINDOW w AS ()', ['users']];
        yield 'order by' => ['ORDER BY id', ['users']];
        yield 'limit' => ['LIMIT 1', ['users']];
        yield 'offset' => ['OFFSET 1', ['users']];
        yield 'returning' => ['RETURNING id', ['users']];
        yield 'union' => ['UNION SELECT * FROM archived', ['users', 'archived']];
        yield 'intersect' => ['INTERSECT SELECT * FROM archived', ['users', 'archived']];
        yield 'except' => ['EXCEPT SELECT * FROM archived', ['users', 'archived']];
    }

    public function testRejectsLiteralAndProducerSourceForms(): void
    {
        $parser = new SqliteSelectRelationParser();

        self::assertSame([], $parser->tableNames("SELECT * FROM 'literal'"));
        self::assertSame([], $parser->tableNames('SELECT * FROM VALUES (1)'));
        self::assertSame([], $parser->tableNames('SELECT * FROM +invalid'));
        self::assertSame([], $parser->tableNames('SELECT * FROM ""'));
        self::assertSame([], $parser->tableNames('SELECT * FROM "unterminated'));
        self::assertSame([], $parser->tableNames('SELECT * FROM `unterminated'));
        self::assertSame([], $parser->tableNames('SELECT * FROM SELECT'));
        self::assertSame([], $parser->tableNames('SELECT * FROM WITH'));
        self::assertSame([], $parser->tableNames('SELECT * FROM [unterminated'));
        self::assertSame([], $parser->tableNames('SELECT * FROM + leaked ]'));
        self::assertSame([], $parser->tableNames("SELECT * FROM 'literal' leaked ]"));
    }

    public function testIdentifierComponentRejectsMismatchedTokenKind(): void
    {
        $method = new \ReflectionMethod(SqliteSelectRelationParser::class, 'identifierComponentAt');
        $token = new SqlToken(SqlTokenKind::String, '"users"', 0, 0, 0);

        self::assertNull($method->invoke(new SqliteSelectRelationParser(), [$token], 0));
    }
}
