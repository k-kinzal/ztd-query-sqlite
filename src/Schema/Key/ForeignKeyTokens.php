<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\Key;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Locates table bodies and foreign-key syntax tokens.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ForeignKeyTokens
{
    /**
     * Locates table bodies and foreign-key syntax tokens.
     */
    public function tableBody(string $sql): ?string
    {
        $tokens = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens();
        $first = $tokens[0] ?? null;
        if ($first === null || !$first->isKeyword('CREATE')) {
            return null;
        }

        $tableFound = false;
        $opening = null;
        foreach ($tokens as $token) {
            if (!$token->isTopLevel()) {
                continue;
            }
            if (!$tableFound) {
                if ($token->isKeyword('TABLE')) {
                    $tableFound = true;
                }
                continue;
            }
            if ($opening === null) {
                if (self::isSymbol($token, '(')) {
                    $opening = $token;
                }
                continue;
            }
            if (self::isSymbol($token, ')')) {
                return substr($sql, $opening->endOffset(), $token->offset - $opening->endOffset());
            }
        }

        return null;
    }

    /**
     * @param list<SqlToken> $tokens
     */
    public static function keywordIndex(array $tokens, string $keyword): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($token->isTopLevel() && $token->isKeyword($keyword)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<SqlToken> $tokens
     */
    public static function symbolIndex(array $tokens, string $symbol, int $start): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($index >= $start && $token->isTopLevel() && self::isSymbol($token, $symbol)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Locates table bodies and foreign-key syntax tokens.
     */
    public static function isSymbol(?SqlToken $token, string $symbol): bool
    {
        if ($token === null) {
            return false;
        }

        return $token->kind === SqlTokenKind::Symbol && $token->text === $symbol;
    }
}
