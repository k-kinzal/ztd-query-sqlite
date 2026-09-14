<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Cte;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteDependencies;

#[CoversClass(CteDependencies::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteHeaderParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteReferences::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class CteDependenciesTest extends TestCase
{
    public function testRequiredKeepsTransitiveDependenciesInDeclarationOrder(): void
    {
        $tables = ['users' => 'users AS (SELECT 1 AS id)', 'view_users' => 'view_users AS (SELECT * FROM users)', 'orders' => 'orders AS (SELECT 2 AS id)'];
        self::assertSame(['users' => $tables['users'], 'view_users' => $tables['view_users']], (new CteDependencies())->required('SELECT * FROM view_users', $tables));
    }

    public function testRequiredRespectsUserDeclarationsAndUnusedFixtures(): void
    {
        $tables = ['users' => 'users AS (SELECT 1 AS id)'];
        self::assertSame([], (new CteDependencies())->required('WITH Users AS (SELECT 2 AS id) SELECT * FROM Users', $tables));
        self::assertSame([], (new CteDependencies())->required('SELECT 1', $tables));
    }

}
