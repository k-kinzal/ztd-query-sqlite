<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteCteShadowComposer;

#[CoversClass(SqliteCteShadowComposer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteCteShadowComposerTest extends TestCase
{
    public function testIncludesTransitivelyReferencedShadowCtes(): void
    {
        self::assertSame(
            "WITH \"items\" AS (SELECT 1 AS id),\n\"item_view\" AS (SELECT * FROM items)\nSELECT * FROM item_view",
            (new SqliteCteShadowComposer())->compose(
                'SELECT * FROM item_view',
                [
                    'items' => '"items" AS (SELECT 1 AS id)',
                    'item_view' => '"item_view" AS (SELECT * FROM items)',
                    'unrelated' => '"unrelated" AS (SELECT 2 AS id)',
                ],
            ),
        );
    }

    public function testAddsShadowsAfterRecursiveModifier(): void
    {
        $sql = 'WITH RECURSIVE tree AS (SELECT id FROM nodes UNION ALL SELECT n.id FROM nodes n JOIN tree t ON n.parent_id = t.id) SELECT * FROM tree';

        self::assertSame(
            "WITH RECURSIVE \"nodes\" AS (SELECT 1 AS id),\n tree AS (SELECT id FROM nodes UNION ALL SELECT n.id FROM nodes n JOIN tree t ON n.parent_id = t.id) SELECT * FROM tree",
            (new SqliteCteShadowComposer())->compose($sql, ['nodes' => '"nodes" AS (SELECT 1 AS id)']),
        );
    }

    public function testPreservesUserCteThatOwnsThePhysicalTableName(): void
    {
        $sql = 'WITH users AS (SELECT 1 AS id), filtered AS (SELECT * FROM users) SELECT * FROM filtered';

        self::assertSame(
            $sql,
            (new SqliteCteShadowComposer())->compose($sql, ['users' => '"users" AS (SELECT 2 AS id)']),
        );
    }

    public function testInjectsReferencedBaseTableBeforeUserCtes(): void
    {
        $sql = 'WITH filtered AS (SELECT * FROM users WHERE active) SELECT * FROM filtered';

        self::assertSame(
            "WITH \"users\" AS (SELECT 1 AS id),\n filtered AS (SELECT * FROM users WHERE active) SELECT * FROM filtered",
            (new SqliteCteShadowComposer())->compose($sql, ['users' => '"users" AS (SELECT 1 AS id)']),
        );
    }

    public function testDoesNotTreatLiteralsCommentsOrIdentifierPrefixesAsReferences(): void
    {
        $sql = "SELECT 'users', superusers.id /* users */ FROM superusers";

        self::assertSame(
            $sql,
            (new SqliteCteShadowComposer())->compose($sql, ['users' => '"users" AS (SELECT 1 AS id)']),
        );
    }

    public function testReadsQuotedAndColumnListedCteNames(): void
    {
        self::assertSame(
            ['first', 'second'],
            (new SqliteCteShadowComposer())->declaredCteNames('WITH "first"(id) AS MATERIALIZED (SELECT 1), second AS (SELECT 2) SELECT * FROM second'),
        );
    }

    public function testCarriesTheCompleteCteHeaderOntoARewrittenDmlProjection(): void
    {
        $sql = 'WITH first AS (SELECT 1), second(id) AS (SELECT * FROM first) UPDATE users SET id = 2';

        self::assertSame(
            "WITH first AS (SELECT 1), second(id) AS (SELECT * FROM first)\nSELECT 2 AS id FROM users",
            (new SqliteCteShadowComposer())->carryPrefix($sql, 'SELECT 2 AS id FROM users'),
        );
    }

    public function testMergesProjectionCtesIntoTheOriginalHeader(): void
    {
        self::assertSame(
            "WITH source AS (SELECT 1),\nprojected AS (SELECT * FROM source)\nSELECT * FROM projected",
            (new SqliteCteShadowComposer())->carryPrefix(
                'WITH source AS (SELECT 1) INSERT INTO target SELECT * FROM source',
                'WITH projected AS (SELECT * FROM source) SELECT * FROM projected',
            ),
        );
    }

    public function testOrdersShadowCtesBeforeUserCtesThatReadThem(): void
    {
        self::assertSame(
            "WITH users AS (SELECT 1 AS id),\nchosen AS (SELECT id FROM users)\nSELECT * FROM users WHERE id IN (SELECT id FROM chosen)",
            (new SqliteCteShadowComposer())->carryPrefix(
                'WITH chosen AS (SELECT id FROM users) UPDATE users SET id = 2',
                'WITH users AS (SELECT 1 AS id) SELECT * FROM users WHERE id IN (SELECT id FROM chosen)',
            ),
        );
    }

    public function testExtractsTheStatementFollowingTheCteHeader(): void
    {
        self::assertSame(
            'DELETE FROM users WHERE id IN (SELECT id FROM chosen)',
            (new SqliteCteShadowComposer())->statementSql('WITH chosen AS (SELECT 1 AS id) DELETE FROM users WHERE id IN (SELECT id FROM chosen)'),
        );
    }

    public function testComposesAReferencedShadowWithoutAnExistingWithClause(): void
    {
        self::assertSame(
            "WITH users AS (SELECT 1 AS id)\nSELECT * FROM users",
            (new SqliteCteShadowComposer())->compose(
                'SELECT * FROM users',
                ['users' => 'users AS (SELECT 1 AS id)'],
            ),
        );
    }

    public function testSkipsEntriesIndependentlyAndMatchesDeclaredNamesCaseInsensitively(): void
    {
        $composer = new SqliteCteShadowComposer();

        self::assertSame(
            "WITH orders AS (SELECT 2 AS id)\nSELECT * FROM orders",
            $composer->compose(
                'SELECT * FROM orders',
                [
                    'users' => 'users AS (SELECT 1 AS id)',
                    'orders' => 'orders AS (SELECT 2 AS id)',
                ],
            ),
        );
        $declared = 'WITH users AS (SELECT 1 AS id) SELECT * FROM users';
        self::assertSame(
            $declared,
            $composer->compose($declared, ['Users' => 'Users AS (SELECT 2 AS id)']),
        );
        self::assertStringContainsString(
            'orders AS (SELECT 2 AS id)',
            $composer->compose(
                'WITH users AS (SELECT 1 AS id) SELECT * FROM users JOIN orders ON TRUE',
                [
                    'orders' => 'orders AS (SELECT 2 AS id)',
                    'Users' => 'Users AS (SELECT 3 AS id)',
                ],
            ),
        );
    }

    public function testHandlesAWithTokenWithoutFollowingHeaderTokens(): void
    {
        self::assertSame(
            "WITH shadow AS (SELECT 1),\n",
            (new SqliteCteShadowComposer())->compose('WITH', ['WITH' => 'shadow AS (SELECT 1)']),
        );
    }

    public function testCarriesAnEmptyRewriteWithoutDereferencingMissingTokens(): void
    {
        self::assertSame(
            "WITH source AS (SELECT 1)\n",
            (new SqliteCteShadowComposer())->carryPrefix(
                'WITH source AS (SELECT 1) UPDATE users SET id = 2',
                '',
            ),
        );
    }

    public function testMergesRecursiveHeadersAndPreservesLeadingComments(): void
    {
        self::assertSame(
            "/* lead */ WITH RECURSIVE projected AS (SELECT 2),\noriginal AS (SELECT 1)\nSELECT * FROM projected",
            (new SqliteCteShadowComposer())->carryPrefix(
                '/* lead */ WITH RECURSIVE original AS (SELECT 1) UPDATE users SET id = 2',
                'WITH projected AS (SELECT 2) SELECT * FROM projected',
            ),
        );
    }

    public function testRecognizesRecursiveRewrittenHeaderDependencies(): void
    {
        self::assertSame(
            "WITH source AS (SELECT 1),\nprojected AS (SELECT * FROM source)\nSELECT * FROM projected",
            (new SqliteCteShadowComposer())->carryPrefix(
                'WITH source AS (SELECT 1) INSERT INTO target SELECT * FROM source',
                'WITH RECURSIVE projected AS (SELECT * FROM source) SELECT * FROM projected',
            ),
        );
    }

    public function testParsesRecursiveMaterializationAndNestedCteBodies(): void
    {
        $sql = 'WITH RECURSIVE "FIRST"(id) AS MATERIALIZED (SELECT (1)), second AS NOT MATERIALIZED (SELECT id FROM "FIRST") DELETE FROM target';
        $composer = new SqliteCteShadowComposer();

        self::assertSame(['first', 'second'], $composer->declaredCteNames($sql));
        self::assertSame('DELETE FROM target', $composer->statementSql($sql));
    }

    public function testHandlesNonHeadersIncompleteHeadersAndEmptyInput(): void
    {
        $composer = new SqliteCteShadowComposer();

        self::assertSame([], $composer->declaredCteNames(''));
        self::assertSame('SELECT 1', $composer->statementSql('SELECT 1'));
        self::assertSame([], $composer->declaredCteNames('WITH only_name'));
        self::assertSame(
            'WITH x AS (SELECT 1)',
            $composer->statementSql('WITH x AS (SELECT 1)'),
        );
        self::assertSame([], $composer->declaredCteNames('WITH x AS'));
        self::assertSame([], $composer->declaredCteNames('WITH x AS NOT'));
        self::assertSame([], $composer->declaredCteNames('WITH x AS (SELECT 1'));
        self::assertSame(
            ['first'],
            $composer->declaredCteNames('WITH first AS (SELECT 1), broken'),
        );
    }

    public function testUnquotesEmptyAndEscapedCteIdentifiers(): void
    {
        $composer = new SqliteCteShadowComposer();

        self::assertSame([''], $composer->declaredCteNames('WITH "" AS (SELECT 1) SELECT 1'));
        self::assertSame(
            ['a"b', 'c`d'],
            $composer->declaredCteNames(
                'WITH "a""b" AS (SELECT 1), `c``d` AS (SELECT 2) SELECT 1',
            ),
        );
    }
    public function testComposesShadowOverSchemaQualifiedSelectSource(): void
    {
        self::assertSame(
            "WITH users AS (SELECT 1 AS id)\nSELECT * FROM \"users\"",
            (new SqliteCteShadowComposer())->compose(
                'SELECT * FROM public."users"',
                ['users' => 'users AS (SELECT 1 AS id)'],
            ),
        );
    }
}
