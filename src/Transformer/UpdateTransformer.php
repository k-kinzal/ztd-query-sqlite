<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Transformer;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Rewrite\SqlTransformer;

/**
 * Transforms UPDATE statements into SELECT projections with CTE shadowing for SQLite.
 *
 * SQLite does not support multi-table UPDATE, so this is simpler than MySQL.
 */
final class UpdateTransformer implements SqlTransformer
{
    private SqliteParser $parser;
    private SelectTransformer $selectTransformer;

    public function __construct(SqliteParser $parser, SelectTransformer $selectTransformer)
    {
        $this->parser = $parser;
        $this->selectTransformer = $selectTransformer;
    }

    /**
     * {@inheritDoc}
     */
    public function transform(string $sql, array $tables): string
    {
        $type = $this->parser->classifyStatement($sql);
        if ($type !== 'UPDATE') {
            throw new UnsupportedSqlException($sql, 'Expected UPDATE statement');
        }

        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve UPDATE target');
        }

        $columns = $tables[$targetTable]['columns'] ?? [];

        $projection = $this->buildProjection($sql, $targetTable, $columns);

        return $this->selectTransformer->transform($projection, $tables);
    }

    /**
     * Build a result-select SQL from an UPDATE statement.
     *
     * @param string $sql
     * @param string $targetTable
     * @param array<int, string> $columns
     * @return string
     */
    public function buildProjection(string $sql, string $targetTable, array $columns): string
    {
        $assignments = $this->parser->extractUpdateAssignments($sql);

        $selectCols = [];
        $coveredCols = [];

        foreach ($assignments as $colName => $value) {
            $selectCols[] = "$value AS \"$colName\"";
            $coveredCols[$colName] = true;
        }

        foreach ($columns as $col) {
            if (!isset($coveredCols[$col])) {
                $selectCols[] = "\"$targetTable\".\"$col\"";
            }
        }

        if ($selectCols === []) {
            $selectCols[] = '*';
        }

        $selectList = implode(', ', $selectCols);

        $whereClause = '';
        $where = $this->parser->extractWhereClause($sql);
        if ($where !== null) {
            $whereClause = " WHERE $where";
        }

        $orderByClause = '';
        $orderBy = $this->parser->extractOrderByClause($sql);
        if ($orderBy !== null) {
            $orderByClause = " ORDER BY $orderBy";
        }

        $limitClause = '';
        $limit = $this->parser->extractLimitClause($sql);
        if ($limit !== null) {
            $limitClause = " LIMIT $limit";
        }

        return "SELECT $selectList FROM \"$targetTable\"$whereClause$orderByClause$limitClause";
    }

    /**
     * Build projection metadata for mutation resolver.
     *
     * @param string $sql
     * @param array<int, string> $columns
     * @return array{sql: string, table: string}
     */
    public function buildProjectionMeta(string $sql, array $columns): array
    {
        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new \RuntimeException('Cannot resolve UPDATE target');
        }

        return [
            'sql' => $this->buildProjection($sql, $targetTable, $columns),
            'table' => $targetTable,
        ];
    }
}
