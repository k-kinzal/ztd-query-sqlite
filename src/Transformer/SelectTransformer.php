<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Transformer;

use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\IdentifierQuoter;
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

    public function __construct(?CastRenderer $castRenderer = null, ?IdentifierQuoter $quoter = null)
    {
        $this->castRenderer = $castRenderer ?? new SqliteCastRenderer();
        $this->quoter = $quoter ?? new SqliteIdentifierQuoter();
    }

    /**
     * {@inheritDoc}
     */
    public function transform(string $sql, array $tables): string
    {
        $ctes = [];
        foreach ($tables as $tableName => $tableContext) {
            if (stripos($sql, $tableName) === false) {
                continue;
            }

            $rows = $tableContext['rows'];
            $columns = $tableContext['columns'];
            $columnTypes = $tableContext['columnTypes'];

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

            $ctes[] = $this->generateCte($tableName, $rows, $columns, $columnTypes);
        }

        if ($ctes === []) {
            return $sql;
        }

        $cteString = implode(",\n", $ctes);
        $pattern = '/^(\s*(?:(?:\/\*.*?\*\/)|(?:--.*?\n)|(?:#.*?\n)|\s)*)WITH\b/is';
        if (preg_match($pattern, $sql) === 1) {
            return (string) preg_replace($pattern, '$1WITH ' . $cteString . ",\n", $sql, 1);
        }

        return "WITH $cteString\n$sql";
    }

    /**
     * Generate a CTE fragment for a single table.
     *
     * @param string $tableName
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     * @param array<string, ColumnType> $columnTypes
     */
    private function generateCte(
        string $tableName,
        array $rows,
        array $columns,
        array $columnTypes
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

                return "$quotedTable AS (SELECT " . implode(', ', $selects) . ' WHERE 0)';
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

            return "$quotedTable AS ($union)";
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

        return "$quotedTable AS ($union)";
    }

    private function formatValue(mixed $val, ?ColumnType $type = null): string
    {
        if (is_null($val)) {
            return 'NULL';
        }

        if ($type !== null) {
            if (is_string($val)) {
                $quotedVal = $this->quoteValue($val);
            } elseif (is_int($val) || is_float($val) || is_bool($val)) {
                $quotedVal = $this->quoteValue((string) $val);
            } else {
                $quotedVal = $this->quoteValue(serialize($val));
            }

            return $this->castRenderer->renderCast($quotedVal, $type);
        }

        if (is_int($val)) {
            return $this->castRenderer->renderCast(
                (string) $val,
                new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'),
            );
        }
        if (is_string($val)) {
            return $this->castRenderer->renderCast(
                $this->quoteValue($val),
                new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
            );
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_float($val)) {
            return (string) $val;
        }
        if (is_object($val) && method_exists($val, '__toString')) {
            return (string) $val;
        }
        throw new \RuntimeException('Unsupported value type for CTE shadowing.');
    }

    private function renderFallbackNullCast(): string
    {
        return $this->castRenderer->renderNullCast(
            new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
        );
    }

    private function quoteValue(string $val): string
    {
        return "'" . str_replace("'", "''", $val) . "'";
    }
}
