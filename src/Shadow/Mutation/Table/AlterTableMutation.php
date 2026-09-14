<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table;

use ZtdQuery\Exception\TableAlreadyExistsException;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\ShadowMutation;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Applies an ALTER TABLE schema and row projection to the shadow store.
 */
final class AlterTableMutation implements ShadowMutation
{
    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct(
        private readonly string $sql,
        private readonly string $sourceTable,
        private readonly string $targetTable,
        private readonly TableDefinition $definition,
        private readonly TableDefinitionRegistry $registry,
        private readonly string $resultSelect,
    ) {
    }

    /**
     * @throws TableAlreadyExistsException
     * {@inheritDoc}
     */
    public function apply(ShadowStore $store, array $rows): void
    {
        if ($this->sourceTable !== $this->targetTable && $this->registry->has($this->targetTable)) {
            throw new TableAlreadyExistsException($this->sql, $this->targetTable);
        }

        $this->registry->markRemoved($this->sourceTable);
        $this->registry->register($this->targetTable, $this->definition);
        if ($this->sourceTable !== $this->targetTable) {
            $store->remove($this->sourceTable);
        }
        $store->set($this->targetTable, $rows);
    }

    /**
     * Returns the SELECT used to project rows after the schema change.
     */
    public function resultSelect(): string
    {
        return $this->resultSelect;
    }

    /**
     * Returns the relation affected by this mutation.
     */
    public function tableName(): string
    {
        return $this->sourceTable;
    }
}
