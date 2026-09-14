<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Attach;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Recognizes the identifier and punctuation suffix of an ATTACH statement.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class AttachTokens
{
    /**
     * @param list<SqlToken> $tokens
     */
    public static function isIdentifierSuffix(array $tokens, int $index): bool
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

    /**
     * Recognizes the identifier and punctuation suffix of an ATTACH statement.
     */
    public static function isSymbol(SqlToken $token, string $symbol): bool
    {
        return $token->kind === SqlTokenKind::Symbol && $token->text === $symbol;
    }
}
