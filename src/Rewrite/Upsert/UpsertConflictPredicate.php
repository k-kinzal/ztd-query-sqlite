<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Upsert;

use ZtdQuery\Platform\IdentifierQuoter;

/**
 * Renders a candidate-key predicate for native upsert evaluation.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class UpsertConflictPredicate
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private IdentifierQuoter $quoter
    ) {
    }

    /**
     * @param array<string, array<int, string>> $candidateKeys
     */
    public function conflictPredicate(array $candidateKeys, string $existingAlias, string $incomingAlias): string
    {
        $keys = [];
        foreach ($candidateKeys as $columns) {
            if ($columns === []) {
                continue;
            }
            $comparisons = [];
            foreach ($columns as $column) {
                $quoted = $this->quoter->quote($column);
                $comparisons[] = "$existingAlias.$quoted = $incomingAlias.$quoted";
            }
            $keys[] = '(' . implode(' AND ', $comparisons) . ')';
        }

        return $keys === [] ? 'FALSE' : '(' . implode(' OR ', $keys) . ')';
    }

    /**
     * Renders a candidate-key predicate for native upsert evaluation.
     */
    public function qualified(string $alias, string $column): string
    {
        return $this->quoter->quote($alias) . '.' . $this->quoter->quote($column);
    }
}
