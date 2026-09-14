<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Lexing;

/**
 * Measures comments and quoted spans that cannot contain statement keywords.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class OpaqueSqlSpan
{
    /**
     * Returns the opaque prefix length, or null when the first byte is syntax.
     */
    public function length(string $sql): ?int
    {
        $first = $sql[0] ?? '';
        if (str_starts_with($sql, '--') || $first === '#') {
            return strcspn($sql, "\r\n") + 1;
        }
        if (str_starts_with($sql, '/*')) {
            $end = strpos($sql, '*/', 2);
            return $end === false ? strlen($sql) : $end + 2;
        }
        if ($first === "'" || $first === '"' || $first === '`') {
            return QuotedSpan::quotedLength($sql, $first);
        }
        if ($first === '[') {
            $end = strpos($sql, ']');
            return $end === false ? strlen($sql) : $end + 1;
        }

        return null;
    }
}
