<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Transformer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer;

#[CoversClass(InsertRowRenderer::class)]
final class InsertRowRendererTest extends TestCase
{
    public function testRenderProvidedExpressionsParsesSqliteDefaultAndRendersCompleteRow(): void
    {
        $renderer = new InsertRowRenderer();
        $provided = $renderer->providedExpressions(['id', 'name'], ['  default  ', "  'Ada'  "]);

        self::assertSame(['name' => "'Ada'"], $provided);
        self::assertSame([
            'id' => '8',
            'name' => "'Ada'",
            'status' => "'active'",
            'note' => 'NULL',
        ], $renderer->render(
            ['id', 'name', 'status', 'note'],
            $provided,
            ['id' => '99', 'status' => "'active'"],
            ['id' => 8],
        ));
    }

    public function testPreservesEveryProvidedSqliteExpression(): void
    {
        self::assertSame(
            ['id' => '42', 'name' => "'Ada'"],
            (new InsertRowRenderer())->providedExpressions(['id', 'name'], ['42', "'Ada'"]),
        );
    }

    public function testProvidedExpressionsOmitsDefaultAndTrimsValues(): void
    {
        self::assertSame(['id' => '7', 'name' => "'Alice'"], (new InsertRowRenderer())->providedExpressions(['id', 'name', 'created'], [' 7 ', " 'Alice' ", ' DEFAULT ']));
    }

}
