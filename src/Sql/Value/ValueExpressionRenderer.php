<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Value;

/**
 * Renders scalar SQL expressions and determines their portable types.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ValueExpressionRenderer
{
    /**
     * Renders scalar SQL expressions and determines their portable types.
     */
    public function renderExpression(int|float|string|bool $value, bool $typed): string
    {
        if (is_bool($value)) {
            return (new ValueLiteralRenderer())->quoteValue($value ? '1' : '0');
        }

        if (is_int($value) && !$typed) {
            return (string) $value;
        }

        if (is_float($value)) {
            return (new ValueLiteralRenderer())->quoteValue(var_export($value, true));
        }

        return (new ValueLiteralRenderer())->quoteValue((string) $value);
    }

}
