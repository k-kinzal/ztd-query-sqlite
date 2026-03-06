<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Transformer\SqliteTransformer;
use ZtdQuery\Rewrite\MultiRewritePlan;
use ZtdQuery\Rewrite\QueryKind;
use ZtdQuery\Rewrite\RewritePlan;
use ZtdQuery\Rewrite\SqlRewriter;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

/**
 * SQLite rewrite implementation for ZTD.
 *
 * Orchestrates parsing, classification, transformation, and mutation resolution.
 * Uses Result Select Query approach (not RETURNING) for consistency.
 */
final class SqliteRewriter implements SqlRewriter
{
    private SqliteQueryGuard $guard;
    private ShadowStore $shadowStore;
    private TableDefinitionRegistry $registry;
    private SqliteTransformer $transformer;
    private SqliteMutationResolver $mutationResolver;
    private SqliteParser $parser;

    public function __construct(
        SqliteQueryGuard $guard,
        ShadowStore $shadowStore,
        TableDefinitionRegistry $registry,
        SqliteTransformer $transformer,
        SqliteMutationResolver $mutationResolver,
        SqliteParser $parser
    ) {
        $this->guard = $guard;
        $this->shadowStore = $shadowStore;
        $this->registry = $registry;
        $this->transformer = $transformer;
        $this->mutationResolver = $mutationResolver;
        $this->parser = $parser;
    }

    /**
     * {@inheritDoc}
     *
     * @throws UnsupportedSqlException When SQL is empty, unparseable, or multi-statement.
     * @throws UnknownSchemaException When SQL references unknown tables/columns.
     */
    public function rewrite(string $sql): RewritePlan
    {
        $statements = $this->parser->splitStatements($sql);
        if ($statements === []) {
            throw new UnsupportedSqlException($sql, 'Empty or unparseable');
        }

        if (count($statements) === 1) {
            return $this->rewriteStatement($statements[0], $sql);
        }

        throw new UnsupportedSqlException($sql, 'Multi-statement');
    }

    /**
     * {@inheritDoc}
     *
     * @throws UnsupportedSqlException When SQL is empty or unparseable.
     * @throws UnknownSchemaException When SQL references unknown tables/columns.
     */
    public function rewriteMultiple(string $sql): MultiRewritePlan
    {
        $statements = $this->parser->splitStatements($sql);

        if ($statements === []) {
            throw new UnsupportedSqlException($sql, 'Empty or unparseable');
        }

        $plans = [];
        foreach ($statements as $statement) {
            $plans[] = $this->rewriteStatement($statement, $statement);
        }

        return new MultiRewritePlan($plans);
    }

    private function rewriteStatement(string $stmtSql, string $originalSql): RewritePlan
    {
        $kind = $this->guard->classify($stmtSql);
        if ($kind === null) {
            throw new UnsupportedSqlException($originalSql, 'Statement type not supported');
        }

        $tableContext = $this->buildTableContext();

        if ($kind === QueryKind::READ) {
            if ($this->hasSchemaContext()) {
                $unknownTable = $this->findUnknownTable($stmtSql);
                if ($unknownTable !== null) {
                    throw new UnknownSchemaException($originalSql, $unknownTable, 'table');
                }
            }

            $transformedSql = $this->transformer->transform($stmtSql, $tableContext);

            return new RewritePlan($transformedSql, QueryKind::READ);
        }

        if ($kind === QueryKind::DDL_SIMULATED) {
            $mutation = $this->mutationResolver->resolve($stmtSql, $kind);

            return new RewritePlan('SELECT 1 WHERE 0', QueryKind::DDL_SIMULATED, $mutation);
        }

        $type = $this->parser->classifyStatement($stmtSql);

        if ($type === 'UPDATE' || $type === 'DELETE') {
            $targetTable = $this->parser->extractTargetTable($stmtSql);
            if ($targetTable !== null) {
                $this->shadowStore->ensure($targetTable);
            }
        }

        $mutation = $this->mutationResolver->resolve($stmtSql, $kind);

        $stripped = trim($this->parser->stripComments($stmtSql));
        if (preg_match('/^DELETE\s+FROM\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s;]+)\s*;?\s*$/i', $stripped) === 1) {
            return new RewritePlan('SELECT 1 WHERE 0', QueryKind::WRITE_SIMULATED, $mutation);
        }

        $transformedSql = $this->transformer->transform($stmtSql, $tableContext);

        return new RewritePlan($transformedSql, QueryKind::WRITE_SIMULATED, $mutation);
    }

    /**
     * Build the table context map for transformers.
     *
     * @return array<string, array{
     *     rows: array<int, array<string, mixed>>,
     *     columns: array<int, string>,
     *     columnTypes: array<string, \ZtdQuery\Schema\ColumnType>
     * }>
     */
    private function buildTableContext(): array
    {
        $context = [];
        $allData = $this->shadowStore->getAll();

        foreach ($allData as $tableName => $rows) {
            $definition = $this->registry->get($tableName);
            $columns = $definition?->columns;
            if ($columns === null && $rows !== []) {
                $columns = array_keys($rows[0]);
                foreach ($rows as $row) {
                    foreach (array_keys($row) as $column) {
                        if (!in_array($column, $columns, true)) {
                            $columns[] = $column;
                        }
                    }
                }
            }

            $columnTypes = $definition !== null ? $definition->typedColumns : [];

            $context[$tableName] = [
                'rows' => $rows,
                'columns' => $columns ?? [],
                'columnTypes' => $columnTypes,
            ];
        }

        $allDefinitions = $this->registry->getAll();
        foreach ($allDefinitions as $tableName => $definition) {
            if (isset($context[$tableName])) {
                continue;
            }

            $context[$tableName] = [
                'rows' => [],
                'columns' => $definition->columns,
                'columnTypes' => $definition->typedColumns,
            ];
        }

        return $context;
    }

    private function findUnknownTable(string $sql): ?string
    {
        $type = $this->parser->classifyStatement($sql);
        if ($type !== 'SELECT') {
            return null;
        }

        $tableNames = $this->parser->extractSelectTables($sql);

        foreach ($tableNames as $tableName) {
            if (!$this->tableExists($tableName)) {
                return $tableName;
            }
        }

        return null;
    }

    private function tableExists(string $tableName): bool
    {
        if ($this->shadowStore->get($tableName) !== []) {
            return true;
        }

        if ($this->registry->has($tableName)) {
            return true;
        }

        return false;
    }

    private function hasSchemaContext(): bool
    {
        if ($this->shadowStore->getAll() !== []) {
            return true;
        }

        if ($this->registry->hasAnyTables()) {
            return true;
        }

        return false;
    }
}
