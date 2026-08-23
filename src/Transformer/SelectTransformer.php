<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Transformer;

use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\SqliteIndexHintStripper;
use ZtdQuery\Platform\Sqlite\SqliteFullTextSearchRewriter;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\IdentifierQuoter;
use ZtdQuery\Platform\ValueRenderer;
use ZtdQuery\Platform\Sqlite\SqliteCteShadowComposer;
use ZtdQuery\Platform\Sqlite\SqliteGeneratedColumnProjector;
use ZtdQuery\Rewrite\SqlTransformer;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;

/**
 * Applies CTE shadowing to SELECT statements for SQLite.
 *
 * Generates WITH clauses that shadow referenced tables using in-memory data.
 * Uses double-quote identifiers and SQLite-compatible CAST types.
 */
final class SelectTransformer implements SqlTransformer
{
    private CastRenderer $castRenderer;
    private IdentifierQuoter $quoter;
    private ValueRenderer $valueRenderer;
    private SqliteCteShadowComposer $cteComposer;
    private SqliteIndexHintStripper $indexHintStripper;
    private SqliteGeneratedColumnProjector $generatedColumnProjector;
    private SqliteFullTextSearchRewriter $fullTextSearchRewriter;

    public function __construct(
        ?CastRenderer $castRenderer = null,
        ?IdentifierQuoter $quoter = null,
        ?ValueRenderer $valueRenderer = null,
    ) {
        $this->castRenderer = $castRenderer ?? new SqliteCastRenderer();
        $this->quoter = $quoter ?? new SqliteIdentifierQuoter();
        $this->valueRenderer = $valueRenderer ?? new \ZtdQuery\Platform\Sqlite\SqliteValueRenderer($this->castRenderer);
        $this->cteComposer = new SqliteCteShadowComposer();
        $this->indexHintStripper = new SqliteIndexHintStripper();
        $this->generatedColumnProjector = new SqliteGeneratedColumnProjector();
        $this->fullTextSearchRewriter = new SqliteFullTextSearchRewriter();
    }

    /**
     * {@inheritDoc}
     */
    public function transform(string $sql, array $tables): string
    {
        $sql = $this->fullTextSearchRewriter->rewrite($sql, $tables);
        $ctes = [];
        foreach ($tables as $tableName => $tableContext) {
            if (isset($tableContext['viewSql'])) {
                $ctes[$tableName] = $this->quoter->quote($tableName) . " AS ({$tableContext['viewSql']})";
                continue;
            }

            $rows = $tableContext['rows'];
            $columns = $tableContext['columns'];
            $columnTypes = $tableContext['columnTypes'];
            $generatedExpressions = $tableContext['generatedExpressions'] ?? [];

            if ($columns === [] && $rows !== []) {
                $columns = array_keys($rows[0]);
                foreach ($rows as $row) {
                    foreach (array_keys($row) as $column) {
                        if (!in_array($column, $columns, true)) {
                            $columns[] = $column;
                        }
                    }
                }
            }

            if ($columns === [] && $rows === []) {
                continue;
            }

            $ctes[$tableName] = $this->generateCte(
                $tableName,
                $rows,
                $columns,
                $columnTypes,
                $generatedExpressions,
            );
        }

        $sql = $this->indexHintStripper->strip($sql, array_keys($ctes));

        return $this->cteComposer->compose($sql, $ctes);
    }

    /**
     * Generate a CTE fragment for a single table.
     *
     * @param string $tableName
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     * @param array<string, ColumnType> $columnTypes
     * @param array<string, string> $generatedExpressions
     */
    private function generateCte(
        string $tableName,
        array $rows,
        array $columns,
        array $columnTypes,
        array $generatedExpressions,
    ): string {
        $quotedTable = $this->quoter->quote($tableName);

        if ($columns !== []) {
            if ($rows === []) {
                $selects = [];
                foreach ($columns as $col) {
                    $type = $columnTypes[$col] ?? null;
                    $nullCast = $type !== null
                        ? $this->castRenderer->renderNullCast($type)
                        : $this->renderFallbackNullCast();
                    $selects[] = "$nullCast AS " . $this->quoter->quote($col);
                }

                return $this->wrapCte(
                    $quotedTable,
                    'SELECT ' . implode(', ', $selects) . ' WHERE 0',
                    $columns,
                    $generatedExpressions,
                );
            }

            $ctes = [];
            foreach ($rows as $row) {
                $selects = [];
                foreach ($columns as $col) {
                    $type = $columnTypes[$col] ?? null;
                    $valStr = $this->formatValue($row[$col] ?? null, $type);
                    $selects[] = "$valStr AS " . $this->quoter->quote($col);
                }
                $ctes[] = 'SELECT ' . implode(', ', $selects);
            }

            $union = implode(' UNION ALL ', $ctes);

            return $this->wrapCte($quotedTable, $union, $columns, $generatedExpressions);
        }

        if ($rows === []) {
            throw new \RuntimeException("Cannot shadow table '$tableName' with empty data (columns unknown).");
        }

        $ctes = [];
        foreach ($rows as $row) {
            $selects = [];
            foreach ($row as $col => $val) {
                $colName = $col;
                $type = $columnTypes[$colName] ?? null;
                $valStr = $this->formatValue($val, $type);
                $selects[] = "$valStr AS " . $this->quoter->quote($colName);
            }
            $ctes[] = 'SELECT ' . implode(', ', $selects);
        }

        $union = implode(' UNION ALL ', $ctes);

        return $this->wrapCte($quotedTable, $union, array_keys($rows[0]), $generatedExpressions);
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, string> $generatedExpressions
     */
    private function wrapCte(
        string $quotedTable,
        string $baseSql,
        array $columns,
        array $generatedExpressions,
    ): string {
        $sql = $this->generatedColumnProjector->project($baseSql, $columns, $generatedExpressions);

        return "$quotedTable AS ($sql)";
    }

    private function formatValue(mixed $val, ?ColumnType $type = null): string
    {
        return $this->valueRenderer->renderValue($val, $type);
    }

    private function renderFallbackNullCast(): string
    {
        return $this->castRenderer->renderNullCast(
            new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
        );
    }

}
