<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Diagnostic;

use ZtdQuery\Sql\SqlTokenStream;

/**
 * Recognizes read-only EXPLAIN statements that can run unchanged.
 */
final class SqliteReadOnlyDiagnosticStatement
{
    /**
     * Returns whether the SQL can pass through without modifying persistent tables.
     */
    public static function isSafe(string $sql): bool
    {
        $stream = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create());
        if (count($stream->splitStatements()) !== 1) {
            return false;
        }

        $tokens = $stream->significantTokens();
        if ($tokens === [] || !$tokens[0]->isKeyword('EXPLAIN') || count($tokens) === 1) {
            return false;
        }

        $diagnosticKind = $tokens[1];
        if ($diagnosticKind->isKeyword('QUERY')) {
            return count($tokens) >= 4 && $tokens[2]->isKeyword('PLAN');
        }

        if ($diagnosticKind->isKeyword('ANALYZE') || $diagnosticKind->isKeyword('ANALYSE')) {
            return false;
        }

        return true;
    }
}
