<?php

declare(strict_types=1);

namespace Tests\Unit\Connection\Parameter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Connection\Parameter\ParameterReplacements;

#[CoversClass(ParameterReplacements::class)]
final class ParameterReplacementsTest extends TestCase
{
    public function testApplyUsesOriginalOffsetsDespiteLengthChanges(): void
    {
        self::assertSame('SELECT CAST(? AS INTEGER), CAST(:name AS TEXT)', (new ParameterReplacements())->apply('SELECT ?, :name', [
            7 => ['length' => 1, 'sql' => 'CAST(? AS INTEGER)'],
            10 => ['length' => 5, 'sql' => 'CAST(:name AS TEXT)'],
        ]));
        self::assertSame('SELECT 1', (new ParameterReplacements())->apply('SELECT 1', []));
    }

}
