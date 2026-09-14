<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Select;

use RuntimeException;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\IdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector;
use ZtdQuery\Platform\ValueRenderer;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\ColumnTypeFamily;

/**
 * Renders typed fixture rows as a named shadow-table CTE.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ShadowCteRenderer
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private CastRenderer $castRenderer,
        private SqliteGeneratedColumnProjector $generatedColumnProjector,
        private IdentifierQuoter $quoter,
        private ValueRenderer $valueRenderer
    ) {
    }

    /**
     * Generate a CTE fragment for a single table.
     *
     * @template TValue
     * @param string $tableName
     * @param array<int, array<string, TValue>> $rows
     * @param array<int, string> $columns
     * @param array<string, ColumnDeclaration> $columnTypes
     * @param array<string, string> $generatedExpressions
     * @throws RuntimeException
     */
    public function generateCte(
        string $tableName,
        array $rows,
        array $columns,
        array $columnTypes,
        array $generatedExpressions,
    ): string {
        if ($columns === [] && $rows === []) {
            throw new RuntimeException("Cannot shadow table '$tableName' with empty data (columns unknown).");
        }
        $projectionColumns = $columns !== [] ? $columns : array_keys($rows[0]);
        $selects = [];
        foreach ($rows as $row) {
            $selects[] = $this->renderRow($row, $columns !== [] ? $columns : array_keys($row), $columnTypes);
        }
        $baseSql = $selects === []
            ? $this->renderEmptyTable($projectionColumns, $columnTypes)
            : implode(' UNION ALL ', $selects);

        return $this->wrapCte($this->quoter->quote($tableName), $baseSql, $projectionColumns, $generatedExpressions);
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, string> $generatedExpressions
     */
    public function wrapCte(
        string $quotedTable,
        string $baseSql,
        array $columns,
        array $generatedExpressions,
    ): string {
        $sql = $this->generatedColumnProjector->project($baseSql, $columns, $generatedExpressions);

        return "$quotedTable AS ($sql)";
    }

    /**
     * Renders typed fixture rows as a named shadow-table CTE.
     */
    public function renderFallbackNullCast(): string
    {
        return $this->castRenderer->renderNullCast(
            new ColumnDeclaration(ColumnTypeFamily::TEXT, 'TEXT'),
        );
    }
    /**
     * Renders fixture values in the declared column order.
     *
     * @template TValue
     * @param array<string, TValue> $row
     * @param array<int, string> $columns
     * @param array<string, ColumnDeclaration> $columnTypes
     */
    public function renderRow(array $row, array $columns, array $columnTypes): string
    {
        $selects = [];
        foreach ($columns as $column) {
            $value = $this->valueRenderer->renderValue($row[$column] ?? null, $columnTypes[$column] ?? null);
            $selects[] = $value . ' AS ' . $this->quoter->quote($column);
        }

        return 'SELECT ' . implode(', ', $selects);
    }

    /**
     * Retains the declared column types even when a table has no fixture rows.
     *
     * @param array<int, string> $columns
     * @param array<string, ColumnDeclaration> $columnTypes
     */
    public function renderEmptyTable(array $columns, array $columnTypes): string
    {
        $selects = [];
        foreach ($columns as $column) {
            $type = $columnTypes[$column] ?? null;
            $value = $type !== null ? $this->castRenderer->renderNullCast($type) : $this->renderFallbackNullCast();
            $selects[] = $value . ' AS ' . $this->quoter->quote($column);
        }

        return 'SELECT ' . implode(', ', $selects) . ' WHERE 0';
    }

}
