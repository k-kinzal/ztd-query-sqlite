<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Value\ValueExpressionRenderer;

#[CoversClass(ValueExpressionRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueLiteralRenderer::class)]
final class ValueExpressionRendererTest extends TestCase
{
    public function testRenderExpressionQuotesTypedValuesAndEscapesStrings(): void
    {
        $renderer = new ValueExpressionRenderer();
        self::assertSame('42', $renderer->renderExpression(42, false));
        self::assertSame("'42'", $renderer->renderExpression(42, true));
        self::assertSame("'1'", $renderer->renderExpression(true, false));
        self::assertSame("'0'", $renderer->renderExpression(false, true));
        self::assertSame("'1.25'", $renderer->renderExpression(1.25, false));
        self::assertSame("'O''Brien'", $renderer->renderExpression("O'Brien", false));
    }

}
