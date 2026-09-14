<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Cte;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Prepends independent generated CTEs while retaining the original recursive prefix.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class CtePrefixMerger
{
    /**
     * Merges a parsed original WITH header with generated CTE declarations.
     */
    public function prepend(string $originalSql, int $statementOffset, string $rewrittenBody, string $rewrittenTail): string
    {
        $originalTokens = SqlTokenStream::tokenize($originalSql, SqliteLexerProfile::create())->significantTokens();
        $originalWith = $originalTokens[0];
        $originalContentToken = $originalWith;
        $recursive = false;
        $originalNext = $originalTokens[1] ?? null;
        if ($originalNext !== null && $originalNext->isKeyword('RECURSIVE')) {
            $originalContentToken = $originalNext;
            $recursive = true;
        }
        $originalBody = trim(substr(
            $originalSql,
            $originalContentToken->endOffset(),
            $statementOffset - $originalContentToken->endOffset(),
        ));
        $leading = substr($originalSql, 0, $originalWith->offset);

        return $leading
            . 'WITH '
            . ($recursive ? 'RECURSIVE ' : '')
            . $rewrittenBody
            . ",\n"
            . $originalBody
            . "\n"
            . $rewrittenTail;
    }
}
