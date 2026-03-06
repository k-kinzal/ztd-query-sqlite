<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Platform\IdentifierQuoter;

/**
 * SQLite implementation of IdentifierQuoter.
 *
 * Uses double-quote quoting ("identifier").
 * Strips surrounding double quotes to prevent double-quoting,
 * then escapes any remaining embedded double quotes by doubling them.
 */
final class SqliteIdentifierQuoter implements IdentifierQuoter
{
    public function quote(string $identifier): string
    {
        $unquoted = $identifier;

        if (strlen($unquoted) >= 2 && $unquoted[0] === '"' && $unquoted[strlen($unquoted) - 1] === '"') {
            $unquoted = substr($unquoted, 1, -1);
            $unquoted = str_replace('""', '"', $unquoted);
        }

        $escaped = str_replace('"', '""', $unquoted);

        return '"' . $escaped . '"';
    }
}
