<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Lexing;

/**
 * Masks comments and string literals while preserving their byte offsets.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class LiteralMasker
{
    /**
     * Strip SQL comments from a string.
     */
    public function stripComments(string $sql): string
    {
        return trim(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::maskComments($sql));
    }

    /**
     * Masks comments and string literals while preserving their byte offsets.
     */
    public function maskStringLiterals(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            if ($sql[$i] !== '\'') {
                $result .= $sql[$i++];
                continue;
            }

            $start = $i++;
            while (true) {
                $end = strpos($sql, '\'', $i);
                if ($end === false) {
                    $i = $length;
                    break;
                }
                $i = $end;
                if (str_starts_with(substr($sql, $i), "''")) {
                    $i += 2;
                    continue;
                }
                $i++;
                break;
            }
            $result .= str_repeat(' ', $i - $start);
        }

        return $result;
    }
}
