<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteNativeUpsertProjector;

#[CoversClass(SqliteNativeUpsertProjector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteNativeUpsertProjectorTest extends TestCase
{
    public function testKeepsIdentifiersInsideScalarSubqueryInTheirNativeScope(): void
    {
        $projector = new SqliteNativeUpsertProjector();

        $result = $projector->project(
            'SELECT 1 AS "id", 0 AS "price"',
            'prices',
            ['id', 'price'],
            ['PRIMARY' => ['id']],
            ['price' => 'price + (SELECT price FROM price_list WHERE id = prices.id) + COALESCE(EXCLUDED.price, 0)'],
        );

        self::assertSame(
            'SELECT "__ztd_incoming"."id" AS "id", "__ztd_incoming"."price" AS "price", '
            . '(SELECT "__ztd_existing"."price" + (SELECT price FROM price_list WHERE id = prices.id) '
            . '+ COALESCE("__ztd_incoming"."price", 0) FROM "prices" AS "__ztd_existing" '
            . 'WHERE (("__ztd_existing"."id" = "__ztd_incoming"."id")) LIMIT 1) AS "__ztd_upsert_value_0" '
            . 'FROM (SELECT 1 AS "id", 0 AS "price") AS "__ztd_incoming"',
            $result,
        );
    }

    public function testBindsQualifiedAndQuotedIdentifiersWithoutChangingForeignNamespaces(): void
    {
        $projector = new SqliteNativeUpsertProjector();

        $result = $projector->project(
            'SELECT 1 AS "id", 2 AS "odd""name", 3 AS "price"',
            'prices',
            ['id', 'odd"name', 'price'],
            ['PRIMARY' => ['id']],
            ['price' => 'EXCLUDED."odd""name" + prices.price + foreign.price + price(price) + CONSTANT'],
        );

        self::assertSame(
            'SELECT "__ztd_incoming"."id" AS "id", "__ztd_incoming"."odd""name" AS "odd""name", '
            . '"__ztd_incoming"."price" AS "price", '
            . '(SELECT "__ztd_incoming"."odd""name" + "__ztd_existing"."price" + foreign.price '
            . '+ price("__ztd_existing"."price") + CONSTANT FROM "prices" AS "__ztd_existing" '
            . 'WHERE (("__ztd_existing"."id" = "__ztd_incoming"."id")) LIMIT 1) AS "__ztd_upsert_value_0" '
            . 'FROM (SELECT 1 AS "id", 2 AS "odd""name", 3 AS "price") AS "__ztd_incoming"',
            $result,
        );
    }

    public function testMatchesColumnsAndIncomingNamespaceCaseInsensitively(): void
    {
        $projector = new SqliteNativeUpsertProjector();

        $result = $projector->project(
            'SELECT 1 AS "ID", 2 AS "PRICE"',
            'ITEMS',
            ['ID', 'PRICE'],
            ['PRIMARY' => ['ID']],
            ['PRICE' => 'EXCLUDED.PRICE + PRICE + ITEMS.PRICE'],
        );

        self::assertSame(
            'SELECT "__ztd_incoming"."ID" AS "ID", "__ztd_incoming"."PRICE" AS "PRICE", '
            . '(SELECT "__ztd_incoming"."PRICE" + "__ztd_existing"."PRICE" + "__ztd_existing"."PRICE" '
            . 'FROM "ITEMS" AS "__ztd_existing" WHERE (("__ztd_existing"."ID" = "__ztd_incoming"."ID")) '
            . 'LIMIT 1) AS "__ztd_upsert_value_0" '
            . 'FROM (SELECT 1 AS "ID", 2 AS "PRICE") AS "__ztd_incoming"',
            $result,
        );
    }

    public function testReturnsIncomingProjectionWhenConflictMetadataIsUnavailable(): void
    {
        $projector = new SqliteNativeUpsertProjector();

        self::assertSame(
            'SELECT 1 AS "id"',
            $projector->project('SELECT 1 AS "id"', 'items', ['id'], [], ['id' => 'EXCLUDED.id']),
        );
        self::assertSame(
            'SELECT 1 AS "id"',
            $projector->project('SELECT 1 AS "id"', 'items', ['id'], ['PRIMARY' => ['id']], []),
        );
    }

    public function testBindsUnqualifiedQuotedIdentifier(): void
    {
        $result = (new SqliteNativeUpsertProjector())->project(
            'SELECT 1 AS "id", 2 AS "odd""name"',
            'items',
            ['id', 'odd"name'],
            ['PRIMARY' => ['id']],
            ['odd"name' => '"odd""name" + 1'],
        );

        self::assertStringContainsString(
            '(SELECT "__ztd_existing"."odd""name" + 1 FROM "items" AS "__ztd_existing"',
            $result,
        );
    }

    public function testBindsConflictPredicateToExistingAndIncomingRows(): void
    {
        $result = (new SqliteNativeUpsertProjector())->project(
            'SELECT \'alice@example.com\' AS "email", \'active\' AS "status", 1 AS "login_count"',
            'users',
            ['email', 'status', 'login_count'],
            ['users_active_email' => ['email']],
            ['login_count' => 'MAX(login_count, EXCLUDED.login_count)'],
            conflictPredicate: "status = 'active'",
        );

        self::assertStringContainsString(
            '"__ztd_existing"."email" = "__ztd_incoming"."email"',
            $result,
        );
        self::assertStringContainsString(
            '("__ztd_existing"."status" = \'active\') AND ("__ztd_incoming"."status" = \'active\')',
            $result,
        );
    }
}
