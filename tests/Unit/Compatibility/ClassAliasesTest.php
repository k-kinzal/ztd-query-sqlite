<?php

declare(strict_types=1);

namespace Tests\Unit\Compatibility;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;
use ZtdQuery\Rewrite\QueryKind;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClassAliasesTest extends TestCase
{
    public function testFormerParserAndGuardNamesStillClassifySql(): void
    {
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);

        self::assertSame(QueryKind::READ, $guard->classify('SELECT id FROM users'));
    }

    public function testFormerParameterTypeAcceptsTheNewTransformerBeforeTheFormerNameIsUsed(): void
    {
        $transformer = new SelectTransformer(new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(), new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        $accept = static fn (\ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer $value): \ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer => $value;

        self::assertSame($transformer, $accept($transformer));
        self::assertSame('SELECT 1', $accept($transformer)->transform('SELECT 1', []));
    }

    public function testNewParameterTypeAcceptsTheFormerTransformerBeforeTheNewNameIsUsed(): void
    {
        $transformer = new \ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer(new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(), new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        $accept = static fn (SelectTransformer $value): SelectTransformer => $value;

        self::assertSame($transformer, $accept($transformer));
        self::assertSame('SELECT 1', $accept($transformer)->transform('SELECT 1', []));
    }
}
