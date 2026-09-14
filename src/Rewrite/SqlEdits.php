<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite;

/**
 * Applies non-overlapping source edits without invalidating earlier byte offsets.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class SqlEdits
{
    /**
     * @param list<array{offset: int, length: int, value: string}> $edits Edits in ascending source order.
     */
    public function apply(string $sql, array $edits): string
    {
        foreach (array_reverse($edits) as $edit) {
            $sql = substr_replace($sql, $edit['value'], $edit['offset'], $edit['length']);
        }

        return $sql;
    }
}
