<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Upsert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertConflictPredicate;

#[CoversClass(UpsertConflictPredicate::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
final class UpsertConflictPredicateTest extends TestCase
{
    public function testConflictPredicateCombinesCompositeAndAlternateKeys(): void
    {
        $predicate = new UpsertConflictPredicate(new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        self::assertSame('((e."tenant" = i."tenant" AND e."id" = i."id") OR (e."email" = i."email"))', $predicate->conflictPredicate(['pk' => ['tenant', 'id'], 'email' => ['email'], 'empty' => []], 'e', 'i'));
        self::assertSame('FALSE', $predicate->conflictPredicate(['empty' => []], 'e', 'i'));
    }

    public function testQualifiedEscapesAliasAndColumn(): void
    {
        self::assertSame('"a""b"."full name"', (new UpsertConflictPredicate(new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter()))->qualified('a"b', 'full name'));
    }

}
