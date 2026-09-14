<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\FullText;

/**
 * Resolves table-wide and column-specific full-text search scopes.
 *
 * @phpstan-import-type TableContext from \ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class FullTextColumns
{
    /**
     * @param array<string, TableContext> $tables
     * @return array<int, string>|null
     */
    public function tableColumns(string $name, array $tables): ?array
    {
        foreach ($tables as $tableName => $context) {
            if (strcasecmp($name, $tableName) !== 0) {
                continue;
            }
            if (isset($context['viewSql'])) {
                return null;
            }

            return $context['columns'];
        }

        return null;
    }

    /**
     * @param array<string, TableContext> $tables
     * @return list<string>|null
     */
    public function matchingColumn(string $name, array $tables): ?array
    {
        $match = null;
        foreach ($tables as $context) {
            if (isset($context['viewSql'])) {
                continue;
            }
            foreach ($context['columns'] as $column) {
                if (strcasecmp($name, $column) !== 0) {
                    continue;
                }
                if ($match !== null) {
                    return null;
                }
                $match = [$column];
            }
        }

        return $match;
    }
}
