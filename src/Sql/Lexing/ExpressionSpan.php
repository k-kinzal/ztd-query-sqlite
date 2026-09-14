<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Lexing;

/**
 * Locates expression delimiters while preserving nested parentheses and quoted values.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ExpressionSpan
{
    /**
     * Returns the next top-level comma, optional closing parenthesis, or end of input.
     */
    public function end(string $sql, int $start, bool $stopAtClosing = true): int
    {
        $depth = 0;
        $length = strlen($sql);
        for ($index = $start; $index < $length; $index++) {
            $char = $sql[$index];
            if ($char === "'" || $char === '"') {
                $index += QuotedSpan::quotedLength(substr($sql, $index), $char) - 1;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if ($depth === 0 && $stopAtClosing) {
                    return $index;
                }
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                return $index;
            }
        }

        return $length;
    }
}
