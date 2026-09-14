<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Context;

use ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinitionSet;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Checks statement relations against known shadow tables and views.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ReferencedTableLookup
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private SqliteCteShadowComposer $cteComposer,
        private SqliteParser $parser,
        private TableDefinitionRegistry $registry,
        private ShadowStore $shadowStore,
        private ViewDefinitionSet $views
    ) {
    }

    /**
     * Checks statement relations against known shadow tables and views.
     */
    public function findUnknownTable(string $sql): ?string
    {
        $type = $this->parser->classifyStatement($sql);
        if ($type !== 'SELECT') {
            return null;
        }

        $tableNames = $this->parser->extractSelectTables($sql);
        $declaredCtes = array_fill_keys($this->cteComposer->declaredCteNames($sql), true);

        foreach ($tableNames as $tableName) {
            if (isset($declaredCtes[strtolower($tableName)])) {
                continue;
            }
            if (!$this->tableExists($tableName)) {
                return $tableName;
            }
        }

        return null;
    }

    /**
     * Checks statement relations against known shadow tables and views.
     */
    public function tableExists(string $tableName): bool
    {
        if ($this->shadowStore->has($tableName)) {
            return true;
        }

        if ($this->registry->has($tableName)) {
            return true;
        }

        if ($this->registry->isRemoved($tableName)) {
            return true;
        }

        if ($this->views->has($tableName)) {
            return true;
        }

        return false;
    }

    /**
     * Checks statement relations against known shadow tables and views.
     */
    public function hasSchemaContext(): bool
    {
        if ($this->shadowStore->getAll() !== []) {
            return true;
        }

        if ($this->registry->hasAnyTables()) {
            return true;
        }

        if ($this->views->hasAnyViews()) {
            return true;
        }

        return false;
    }
    /**
     * Rejects unknown read sources only when a schema or fixture context exists.
     *
     * @throws \ZtdQuery\Exception\UnknownSchemaException
     */
    public function assertKnownTables(string $sql, string $originalSql): void
    {
        if (!$this->hasSchemaContext()) {
            return;
        }
        $unknownTable = $this->findUnknownTable($sql);
        if ($unknownTable !== null) {
            throw new \ZtdQuery\Exception\UnknownSchemaException($originalSql, $unknownTable, 'table');
        }
    }

}
