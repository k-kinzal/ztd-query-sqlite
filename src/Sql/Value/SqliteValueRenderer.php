<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Value;

use RuntimeException;
use Stringable;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\ValueRenderer;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\ColumnTypeFamily;

/**
 * Encodes shadow values while preserving SQLite storage classes.
 */
final class SqliteValueRenderer implements ValueRenderer
{
    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct(private readonly CastRenderer $castRenderer = new SqliteCastRenderer())
    {
    }

    /**
     * Returns render value.
     * @throws RuntimeException
     */
    public function renderValue(mixed $value, ?ColumnDeclaration $type = null): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($type === null && is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($type === null && is_float($value)) {
            return var_export($value, true);
        }

        if ($type === null && $value instanceof Stringable) {
            return (string) $value;
        }

        if ($type === null && !is_scalar($value)) {
            throw new RuntimeException('Unsupported value type for CTE shadowing.');
        }

        $resolvedType = $type ?? (is_int($value) ? new ColumnDeclaration(ColumnTypeFamily::INTEGER, 'INTEGER') : new ColumnDeclaration(ColumnTypeFamily::TEXT, 'TEXT'));
        if ($resolvedType->family === ColumnTypeFamily::BINARY) {
            if (is_resource($value) && get_resource_type($value) === 'stream') {
                $value = (new ValueLiteralRenderer())->readStream($value);
            }
            if (!is_scalar($value) && !$value instanceof Stringable) {
                throw new RuntimeException('Unsupported value type for CTE shadowing.');
            }
            $expression = "X'" . bin2hex((string) $value) . "'";
        } else {
            $normalized = is_scalar($value) ? $value : ($value instanceof Stringable ? (string) $value : serialize($value));
            $expression = (new ValueExpressionRenderer())->renderExpression($normalized, $type !== null);
        }

        return $this->castRenderer->renderCast($expression, $resolvedType);
    }

}
