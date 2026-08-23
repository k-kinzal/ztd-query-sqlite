<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Transformer;

use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Rewrite\InsertSelectProjectionPlanner;

final class InsertSelectRenderer
{
    private SqliteIdentifierQuoter $quoter;
    private InsertSelectProjectionPlanner $projectionPlanner;

    public function __construct()
    {
        $this->quoter = new SqliteIdentifierQuoter();
        $this->projectionPlanner = new InsertSelectProjectionPlanner();
    }

    /**
     * @param list<string> $tableColumns
     * @param list<string> $insertColumns
     * @param array<string, string> $defaults
     * @param array<string, int> $generatedIdentityStarts
     */
    public function render(
        string $selectSql,
        array $tableColumns,
        array $insertColumns,
        array $defaults,
        array $generatedIdentityStarts = [],
    ): string {
        $sourceColumns = [];
        foreach ($insertColumns as $index => $column) {
            $sourceColumns[] = $this->quoter->quote('__ztd_insert_' . $index);
        }

        $selects = [];
        foreach ($this->projectionPlanner->plan($tableColumns, $insertColumns, $defaults, $generatedIdentityStarts) as $projection) {
            $sourceIndex = $projection->sourceIndex();
            $generatedIdentityStart = $projection->generatedIdentityStart();
            if ($sourceIndex !== null) {
                $expression = $this->quoter->quote('__ztd_insert_' . $sourceIndex);
            } elseif ($generatedIdentityStart !== null) {
                $expression = $this->renderGeneratedIdentity($generatedIdentityStart);
            } else {
                $expression = $projection->defaultExpressionValue() ?? 'NULL';
            }
            $selects[] = $expression . ' AS ' . $this->quoter->quote($projection->targetColumn());
        }

        $sourceName = $this->quoter->quote('__ztd_insert_source');

        return 'WITH ' . $sourceName . ' (' . implode(', ', $sourceColumns) . ') AS ('
            . $selectSql . ') SELECT ' . implode(', ', $selects) . ' FROM ' . $sourceName;
    }

    public function renderGeneratedIdentity(int $start): string
    {
        if ($start < 1) {
            throw new \InvalidArgumentException('Generated identity start must be positive.');
        }

        return $start . ' + ROW_NUMBER() OVER () - 1';
    }
}
