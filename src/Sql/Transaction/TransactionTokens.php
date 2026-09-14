<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Transaction;

use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;

/**
 * Recognizes transaction token sequences and decodes savepoint names.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class TransactionTokens
{
    /**
     * @param list<SqlToken> $tokens
     * @param list<list<string>> $forms
     */
    public function matchesAny(array $tokens, array $forms): bool
    {
        foreach ($forms as $form) {
            if ($this->matches($tokens, $form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<SqlToken> $tokens
     * @param list<list<string>> $prefixes
     */
    public function nameAfter(array $tokens, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix) {
            if (count($tokens) !== count($prefix) + 1 || !$this->matches(array_slice($tokens, 0, -1), $prefix)) {
                continue;
            }
            $name = $tokens[count($prefix)];
            if (!in_array($name->kind, [SqlTokenKind::Word, SqlTokenKind::QuotedIdentifier], true)) {
                return null;
            }

            return $this->unquote($name->text);
        }

        return null;
    }

    /**
     * @param list<SqlToken> $tokens
     * @param list<string> $keywords
     */
    public function matches(array $tokens, array $keywords): bool
    {
        if (count($tokens) !== count($keywords)) {
            return false;
        }
        foreach ($keywords as $index => $keyword) {
            if (!$tokens[$index]->isKeyword($keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recognizes transaction token sequences and decodes savepoint names.
     */
    public function unquote(string $identifier): ?string
    {
        $first = $identifier[0] ?? '';
        if (!in_array($first, ['"', '`'], true)) {
            return $identifier;
        }
        if (($identifier[strlen($identifier) - 1] ?? '') !== $first) {
            return null;
        }

        return str_replace($first . $first, $first, substr($identifier, 1, -1));
    }
}
