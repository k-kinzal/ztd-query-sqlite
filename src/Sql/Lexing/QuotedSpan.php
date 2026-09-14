<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Lexing;

/**
 * Measures quoted SQL spans without treating their contents as syntax.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class QuotedSpan
{
    /**
     * Measures quoted SQL spans without treating their contents as syntax.
     */
    public static function quotedLength(string $sql, string $quote): int
    {
        $length = strlen($sql);
        $i = 1;

        while (true) {
            $end = strpos($sql, $quote, $i);
            if ($end === false) {
                return $length;
            }
            $quoteCount = strspn($sql, $quote, $end);
            $i = $end + $quoteCount;
            if ($quoteCount % 2 === 0) {
                continue;
            }

            return $i;
        }
    }

    /**
     * Measures quoted SQL spans without treating their contents as syntax.
     */
    public static function bracketQuotedLength(string $sql): int
    {
        $end = strpos($sql, ']');
        if ($end === false) {
            return strlen($sql);
        }

        return $end;
    }
}
