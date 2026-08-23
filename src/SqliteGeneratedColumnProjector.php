<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Platform\IdentifierQuoter;

/**
 * Recomputes database-generated columns from a complete base-row projection.
 */
final class SqliteGeneratedColumnProjector
{
    private const SOURCE_ALIAS = '__ztd_generated_source';

    private readonly IdentifierQuoter $quoter;

    public function __construct()
    {
        $this->quoter = new SqliteIdentifierQuoter();
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, string> $generatedExpressions
     */
    public function project(string $sourceSql, array $columns, array $generatedExpressions): string
    {
        if ($generatedExpressions === []) {
            return $sourceSql;
        }

        $sourceAlias = $this->quoter->quote(self::SOURCE_ALIAS);
        $selects = [];
        foreach ($columns as $column) {
            $quotedColumn = $this->quoter->quote($column);
            $expression = $generatedExpressions[$column] ?? null;
            $selects[] = $expression === null
                ? "$sourceAlias.$quotedColumn AS $quotedColumn"
                : "$expression AS $quotedColumn";
        }

        return 'SELECT ' . implode(', ', $selects) . " FROM ($sourceSql) AS $sourceAlias";
    }
}
