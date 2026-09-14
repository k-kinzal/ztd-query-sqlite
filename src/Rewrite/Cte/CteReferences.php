<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Cte;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Detects identifier dependencies between CTE declarations.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class CteReferences
{
    /**
     * Detects identifier dependencies between CTE declarations.
     */
    public function referencesIdentifier(string $sql, string $identifier): bool
    {
        foreach (SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens() as $token) {
            $candidate = (new CteHeaderParser())->identifierName($token);
            if ($candidate !== null && strcasecmp($candidate, $identifier) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $identifiers
     */
    public function referencesAnyIdentifier(string $sql, array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if ($this->referencesIdentifier($sql, $identifier)) {
                return true;
            }
        }

        return false;
    }
}
