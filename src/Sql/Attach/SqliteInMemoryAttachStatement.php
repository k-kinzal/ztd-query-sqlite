<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Attach;

use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Recognizes ATTACH statements that can only create an in-memory database.
 */
final class SqliteInMemoryAttachStatement
{
    /**
     * Returns whether the SQL can pass through without modifying persistent tables.
     */
    public static function isSafe(string $sql): bool
    {
        $tokens = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens();
        $last = $tokens[count($tokens) - 1] ?? null;
        if ($last !== null && AttachTokens::isSymbol($last, ';')) {
            array_pop($tokens);
        }

        if (count($tokens) < 4 || !$tokens[0]->isKeyword('ATTACH')) {
            return false;
        }

        $pathIndex = 1;
        if ($tokens[$pathIndex]->isKeyword('DATABASE')) {
            $pathIndex++;
        }

        $path = $tokens[$pathIndex];
        if ($path->kind !== SqlTokenKind::String || $path->text !== "':memory:'") {
            return false;
        }

        $as = $tokens[$pathIndex + 1];
        if (!$as->isKeyword('AS')) {
            return false;
        }

        return AttachTokens::isIdentifierSuffix($tokens, $pathIndex + 2);
    }

}
