<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Cte;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteReferences;

#[CoversClass(CteReferences::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Cte\CteHeaderParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class CteReferencesTest extends TestCase
{
    public function testReferencesIdentifierIgnoresCommentsAndStringLiterals(): void
    {
        $references = new CteReferences();
        self::assertTrue($references->referencesIdentifier('SELECT * FROM "Users"', 'users'));
        self::assertFalse($references->referencesIdentifier("SELECT 'users' /* users */", 'users'));
        self::assertFalse($references->referencesIdentifier('SELECT * FROM other_users', 'users'));
    }

    public function testReferencesAnyIdentifierChecksAllCandidates(): void
    {
        $references = new CteReferences();
        self::assertTrue($references->referencesAnyIdentifier('SELECT * FROM users', ['orders', 'users']));
        self::assertFalse($references->referencesAnyIdentifier('SELECT * FROM users', ['orders']));
        self::assertFalse($references->referencesAnyIdentifier('SELECT * FROM users', []));
    }

}
