<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Transformer;

use RuntimeException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Rewrite\SqlTransformer;
use ZtdQuery\Shadow\Mutation\MutationRowIdentity;

/**
 * Transforms UPDATE statements into SELECT projections with CTE shadowing for SQLite.
 *
 * SQLite does not support multi-table UPDATE, so this is simpler than MySQL.
 */
final class UpdateTransformer implements SqlTransformer
{
    private SqliteParser $parser;
    private SelectTransformer $selectTransformer;
    private SqliteCteShadowComposer $cteComposer;

    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct(
        SqliteParser $parser,
        SelectTransformer $selectTransformer,
    ) {
        $this->parser = $parser;
        $this->selectTransformer = $selectTransformer;
        $this->cteComposer = new SqliteCteShadowComposer();
    }

    /**
     * {@inheritDoc}
     * @throws UnsupportedSqlException
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

        $projection = $this->buildProjection(
            $sql,
            $targetTable,
            $columns,
            $tables[$targetTable]['primaryKeys'] ?? [],
        );

        return $this->selectTransformer->transform(
            $this->cteComposer->carryPrefix($sql, $projection),
            $tables,
        );
    }

    /**
     * Build a result-select SQL from an UPDATE statement.
     *
     * @param string $sql
     * @param string $targetTable
     * @param array<int, string> $columns
     * @param array<int, string> $primaryKeys
     * @return string
     */
    public function buildProjection(
        string $sql,
        string $targetTable,
        array $columns,
        array $primaryKeys = [],
    ): string {
        $assignments = $this->parser->extractUpdateAssignments($sql);
        $alias = $this->parser->extractUpdateAlias($sql);
        $qualifier = $alias ?? $targetTable;

        $selectCols = [];
        $coveredCols = [];

        foreach ($assignments as $colName => $value) {
            $selectCols[] = "$value AS \"$colName\"";
            $coveredCols[$colName] = true;
        }

        foreach ($columns as $col) {
            if (!isset($coveredCols[$col])) {
                $selectCols[] = "\"$qualifier\".\"$col\"";
            }
        }

        $identity = new MutationRowIdentity();
        foreach ($primaryKeys as $primaryKey) {
            $selectCols[] = '"' . $qualifier . '"."' . $primaryKey . '" AS "' . $identity->column($primaryKey) . '"';
        }

        if ($selectCols === []) {
            $selectCols[] = '*';
        }

        $selectList = implode(', ', $selectCols);

        $aliasClause = $alias === null ? '' : ' AS "' . $alias . '"';
        $fromClause = $this->parser->extractUpdateFromClause($sql);
        $additionalFrom = $fromClause === null ? '' : ', ' . $fromClause;

        $where = $this->parser->extractWhereClause($sql);
        $whereClause = $where === null ? '' : " WHERE $where";
        $orderBy = $this->parser->extractOrderByClause($sql);
        $orderByClause = $orderBy === null ? '' : " ORDER BY $orderBy";
        $limit = $this->parser->extractLimitClause($sql);
        $limitClause = $limit === null ? '' : " LIMIT $limit";

        return "SELECT $selectList FROM \"$targetTable\"$aliasClause$additionalFrom$whereClause$orderByClause$limitClause";
    }

    /**
     * Build projection metadata for mutation resolver.
     *
     * @param string $sql
     * @param array<int, string> $columns
     * @return array{sql: string, table: string}
     * @throws RuntimeException
     */
    public function buildProjectionMeta(string $sql, array $columns): array
    {
        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new RuntimeException('Cannot resolve UPDATE target');
        }

        return [
            'sql' => $this->buildProjection($sql, $targetTable, $columns),
            'table' => $targetTable,
        ];
    }
}
