<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\SqlEdits;

#[CoversClass(SqlEdits::class)]
final class SqlEditsTest extends TestCase
{
    public function testApplyPreservesOriginalByteOffsets(): void
    {
        self::assertSame('SELECT long_column, short', (new SqlEdits())->apply('SELECT a, b', [
            ['offset' => 7, 'length' => 1, 'value' => 'long_column'],
            ['offset' => 10, 'length' => 1, 'value' => 'short'],
        ]));
        self::assertSame('SELECT 1', (new SqlEdits())->apply('SELECT 1', []));
    }

}
