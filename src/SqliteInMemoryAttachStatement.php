<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

final class SqliteInMemoryAttachStatement
{
    public static function isSafe(string $sql): bool
    {
        $tokens = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens();
        $last = $tokens[count($tokens) - 1] ?? null;
        if ($last !== null && self::isSymbol($last, ';')) {
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

        return self::isIdentifierSuffix($tokens, $pathIndex + 2);
    }

    /**
     * @param list<SqlToken> $tokens
     */
    private static function isIdentifierSuffix(array $tokens, int $index): bool
    {
        if (count($tokens) - $index !== 1) {
            return false;
        }
        $token = $tokens[$index];
        if ($token->kind === SqlTokenKind::Word) {
            return true;
        }
        $identifier = SqliteLexerProfile::create()->quotedIdentifierValue($token->text);
        if ($identifier === null) {
            return false;
        }
        if (!str_starts_with($token->text, '[')) {
            return true;
        }
        $identifierTokens = SqlTokenStream::tokenize($identifier, SqliteLexerProfile::create())->significantTokens();

        return count($identifierTokens) === 1
            && $identifierTokens[0]->kind === SqlTokenKind::Word
            && $identifierTokens[0]->text === $identifier;
    }

    private static function isSymbol(SqlToken $token, string $symbol): bool
    {
        return $token->kind === SqlTokenKind::Symbol && $token->text === $symbol;
    }
}
