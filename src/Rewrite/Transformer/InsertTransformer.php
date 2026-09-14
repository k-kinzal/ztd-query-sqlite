<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Transformer;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer;
use ZtdQuery\Platform\Sqlite\Rewrite\Upsert\SqliteNativeUpsertProjector;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;
use ZtdQuery\Rewrite\ShadowIdentityAllocator;
use ZtdQuery\Rewrite\SqlTransformer;

/**
 * Transforms INSERT/REPLACE statements into SELECT queries that return the inserted rows.
 * Applies CTE shadowing via the SelectTransformer delegate.
 *
 * Handles:
 * - INSERT INTO ... VALUES (...)
 * - INSERT OR REPLACE INTO ... VALUES (...)
 * - REPLACE INTO ... VALUES (...)
 * - INSERT INTO ... SELECT ...
 * - INSERT INTO ... ON CONFLICT ... DO UPDATE SET ...
 */
final class InsertTransformer implements SqlTransformer
{
    private SqliteParser $parser;
    private SelectTransformer $selectTransformer;
    private CastRenderer $castRenderer;
    private InsertRowRenderer $rowRenderer;
    private ShadowIdentityAllocator $identityAllocator;
    private InsertSelectRenderer $insertSelectRenderer;
    private SqliteCteShadowComposer $cteComposer;
    private SqliteNativeUpsertProjector $upsertProjector;

    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct(
        SqliteParser $parser,
        SelectTransformer $selectTransformer,
        ?CastRenderer $castRenderer = null,
    ) {
        $this->parser = $parser;
        $this->selectTransformer = $selectTransformer;
        $this->castRenderer = $castRenderer ?? new SqliteCastRenderer();
        $this->rowRenderer = new InsertRowRenderer();
        $this->identityAllocator = new ShadowIdentityAllocator();
        $this->insertSelectRenderer = new InsertSelectRenderer();
        $this->cteComposer = new SqliteCteShadowComposer();
        $this->upsertProjector = new SqliteNativeUpsertProjector();
    }

    /**
     * {@inheritDoc}
     * @throws UnsupportedSqlException
     */
    public function transform(string $sql, array $tables): string
    {
        $this->identityAllocator->beginProjection();
        $type = $this->parser->classifyStatement($sql);
        if ($type !== 'INSERT') {
            throw new UnsupportedSqlException($sql, 'Expected INSERT/REPLACE statement');
        }

        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve INSERT target');
        }

        $insertColumns = Insert\InsertProjectionBuilder::orderedValues($this->parser->extractInsertColumns($sql));
        $tableColumns = Insert\InsertProjectionBuilder::orderedValues($tables[$tableName]['columns'] ?? $insertColumns);
        if ($tableColumns === []) {
            throw new UnsupportedSqlException($sql, 'Cannot determine columns');
        }

        $columnTypes = $tables[$tableName]['columnTypes'] ?? [];
        $columnDefaults = $tables[$tableName]['columnDefaults'] ?? [];
        $identityStrategies = $tables[$tableName]['identityStrategies'] ?? [];
        $existingRows = $tables[$tableName]['rows'] ?? [];
        $selectSql = (new Insert\InsertProjectionBuilder($this->castRenderer, $this->identityAllocator, $this->insertSelectRenderer, $this->parser, $this->rowRenderer))->buildInsertSelect(
            $sql,
            $tableName,
            $tableColumns,
            $insertColumns,
            $columnTypes,
            $columnDefaults,
            $identityStrategies,
            $existingRows,
        );
        $selectSql = $this->upsertProjector->project(
            $selectSql,
            $tableName,
            $tableColumns,
            isset($tables[$tableName]['candidateKeys']) ? $tables[$tableName]['candidateKeys'] : [],
            $this->parser->extractOnConflictUpdates($sql),
            $this->parser->extractOnConflictUpdateWhere($sql),
        );

        return $this->selectTransformer->transform(
            $this->cteComposer->carryPrefix($sql, $selectSql),
            $tables,
        );
    }

    /**
     * Commits the transformer state after a rewrite completes.
     */
    public function commitRewriteState(): void
    {
        $this->identityAllocator->commitProjection();
    }

}
