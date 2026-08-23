<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqlitePdoParameterBindingCompiler;
use ZtdQuery\Schema\ColumnType;

#[CoversClass(SqlitePdoParameterBindingCompiler::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqlitePdoParameterBindingCompilerTest extends TestCase
{
    public function testUsesSqliteRendererForStorageSensitiveParameterTypes(): void
    {
        $compiled = (new SqlitePdoParameterBindingCompiler())->compile(
            'SELECT ?, ?, :ratio, :integer, :enabled, \'?\', "?"',
            [0 => 12, 1 => 2.5, 'ratio' => 2.5, ':integer' => 9, 'enabled' => true],
        );

        self::assertSame(
            'SELECT CAST(? AS INTEGER), CAST(? AS REAL), CAST(:ratio AS REAL), CAST(:integer AS INTEGER), CAST(:enabled AS INTEGER), \'?\', "?"',
            $compiled['sql'],
        );
    }

    public function testLeavesNullAndUnboundParametersUnchanged(): void
    {
        $compiler = new SqlitePdoParameterBindingCompiler();

        self::assertSame(
            ['sql' => 'SELECT ?, :missing', 'params' => null],
            $compiler->compile('SELECT ?, :missing', null),
        );
        self::assertSame(
            ['sql' => 'SELECT ?, :missing', 'params' => []],
            $compiler->compile('SELECT ?, :missing', []),
        );
        self::assertSame(
            ['sql' => 'SELECT :missing, CAST(:value AS INTEGER)', 'params' => ['value' => 7]],
            $compiler->compile('SELECT :missing, :value', ['value' => 7]),
        );
    }

    public function testLeavesBoundValuesWithoutStorageSensitiveTypesUnchanged(): void
    {
        $params = [null, 'text', []];

        self::assertSame(
            ['sql' => 'SELECT ?, ?, ?', 'params' => $params],
            (new SqlitePdoParameterBindingCompiler())->compile('SELECT ?, ?, ?', $params),
        );
    }
}
