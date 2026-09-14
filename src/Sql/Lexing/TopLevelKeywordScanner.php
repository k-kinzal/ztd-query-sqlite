<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Lexing;

/**
 * Locates unquoted top-level keywords and completed parenthesized groups.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class TopLevelKeywordScanner
{
    /**
     * @return array<int, array{keyword: string, afterGroup: bool, offset: int}>
     */
    public function scanTopLevelKeywords(string $sql): array
    {
        $keywords = [];
        $len = strlen($sql);
        $depth = 0;
        $completedGroup = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $opaqueLength = (new OpaqueSqlSpan())->length(substr($sql, $i));
            if ($opaqueLength !== null) {
                $i += $opaqueLength - 1;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0) {
                        $completedGroup = true;
                    }
                }
                continue;
            }

            $start = $i;
            $tokenLength = strspn($sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$', $i);
            if ($tokenLength === 0) {
                continue;
            }

            $token = substr($sql, $i, $tokenLength);
            $i += $tokenLength - 1;
            if ($depth === 0 && strspn($token, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') > 0) {
                $keywords[] = [
                    'keyword' => strtoupper($token),
                    'afterGroup' => $completedGroup,
                    'offset' => $start,
                ];
            }
        }

        return $keywords;
    }
}
