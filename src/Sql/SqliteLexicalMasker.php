<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql;

/**
 * Replaces SQL comments with whitespace while preserving quoted content.
 */
final class SqliteLexicalMasker
{
    /**
     * Returns mask comments.
     */
    public static function maskComments(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\'' || $char === '"' || $char === '`') {
                $tail = substr($sql, $i);
                $quotedLength = Lexing\QuotedSpan::quotedLength($tail, $char);
                $result .= substr($tail, 0, $quotedLength);
                $i += $quotedLength;
                continue;
            }

            if ($char === '[') {
                $tail = substr($sql, $i);
                $quotedLength = Lexing\QuotedSpan::bracketQuotedLength($tail);
                $result .= substr($tail, 0, $quotedLength);
                $i += $quotedLength;
                continue;
            }

            $pair = substr($sql, $i, 2);
            if ($pair === '--' || $char === '#') {
                $commentLength = strcspn($sql, "\r\n", $i);
                $result .= ' ';
                $i += $commentLength;
                continue;
            }

            if ($pair === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $result .= ' ';
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }

}
