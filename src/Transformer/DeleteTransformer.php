<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Transformer;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Rewrite\SqlTransformer;

/**
 * Transforms DELETE statements into SELECT projections with CTE shadowing for SQLite.
 *
 * SQLite does not support multi-table DELETE, so this is simpler than MySQL.
 */
final class DeleteTransformer implements SqlTransformer
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
        if ($type !== 'DELETE') {
            throw new UnsupportedSqlException($sql, 'Expected DELETE statement');
        }

        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve DELETE target');
        }

        $columns = $tables[$targetTable]['columns'] ?? [];

        $projection = $this->buildProjection($sql, $targetTable, $columns);

        return $this->selectTransformer->transform($projection, $tables);
    }

    /**
     * Build a result-select SQL from a DELETE statement.
     *
     * @param string $sql
     * @param string $targetTable
     * @param array<int, string> $columns
     * @return string
     */
    public function buildProjection(string $sql, string $targetTable, array $columns): string
    {
        $selectList = "\"$targetTable\".*";
        if ($columns !== []) {
            $parts = [];
            foreach ($columns as $column) {
                $parts[] = "\"$targetTable\".\"$column\" AS \"$column\"";
            }
            $selectList = implode(', ', $parts);
        }

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
}
