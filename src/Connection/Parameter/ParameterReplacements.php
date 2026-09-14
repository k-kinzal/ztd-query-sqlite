<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Connection\Parameter;

/**
 * Applies parameter casts from right to left without shifting pending byte offsets.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ParameterReplacements
{
    /**
     * @param array<int, array{length: int, sql: string}> $replacements Cast expressions keyed by their original offset.
     */
    public function apply(string $sql, array $replacements): string
    {
        krsort($replacements);
        foreach ($replacements as $offset => $replacement) {
            $sql = substr_replace($sql, $replacement['sql'], $offset, $replacement['length']);
        }

        return $sql;
    }
}
