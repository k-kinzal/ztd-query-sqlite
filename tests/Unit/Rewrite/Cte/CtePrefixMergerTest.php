<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Cte;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\CtePrefixMerger;

#[CoversClass(CtePrefixMerger::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class CtePrefixMergerTest extends TestCase
{
    public function testPrependRetainsLeadingCommentsAndRecursiveKeyword(): void
    {
        $sql = '/*hint*/ WITH RECURSIVE t AS (SELECT 1) SELECT * FROM t';
        self::assertSame("/*hint*/ WITH RECURSIVE users AS (SELECT 2),\nt AS (SELECT 1)\nSELECT * FROM t", (new CtePrefixMerger())->prepend($sql, 40, 'users AS (SELECT 2)', 'SELECT * FROM t'));
    }

    public function testPrependRetainsNonRecursiveHeader(): void
    {
        self::assertSame("WITH users AS (SELECT 2),\nt AS (SELECT 1)\nSELECT * FROM t", (new CtePrefixMerger())->prepend('WITH t AS (SELECT 1) SELECT * FROM t', 21, 'users AS (SELECT 2)', 'SELECT * FROM t'));
    }

}
