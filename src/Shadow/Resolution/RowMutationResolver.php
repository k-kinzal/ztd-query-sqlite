<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Resolution;

use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\Row\DeleteMutation;
use ZtdQuery\Shadow\Mutation\Row\UpdateMutation;
use ZtdQuery\Shadow\Mutation\ShadowMutation;
use ZtdQuery\Shadow\ShadowStore;
use ZtdQuery\Shadow\ShadowTableState;

/**
 * Resolves row updates and deletions against the current shadow schema.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class RowMutationResolver
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private SqliteParser $parser,
        private TableDefinitionRegistry $registry,
        private ShadowStore $shadowStore
    ) {
    }

    /**
     * Resolves row updates and deletions against the current shadow schema.
     * @throws UnknownSchemaException
     * @throws UnsupportedSqlException
     */
    public function resolveUpdate(string $sql): ShadowMutation
    {
        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve UPDATE target');
        }
        (new MutationTableLookup($this->registry))->assertTableWasNotRemoved($sql, $targetTable);

        $definition = $this->registry->get($targetTable);
        if ($definition === null && $this->shadowStore->state($targetTable) !== ShadowTableState::Initialized) {
            throw new UnknownSchemaException($sql, $targetTable, 'table');
        }

        $this->shadowStore->ensure($targetTable);
        $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

        return new UpdateMutation($targetTable, $primaryKeys);
    }

    /**
     * Resolves row updates and deletions against the current shadow schema.
     * @throws UnknownSchemaException
     * @throws UnsupportedSqlException
     */
    public function resolveDelete(string $sql): ShadowMutation
    {
        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve DELETE target');
        }
        (new MutationTableLookup($this->registry))->assertTableWasNotRemoved($sql, $targetTable);

        $definition = $this->registry->get($targetTable);
        if ($definition === null && $this->shadowStore->state($targetTable) !== ShadowTableState::Initialized) {
            throw new UnknownSchemaException($sql, $targetTable, 'table');
        }

        $this->shadowStore->ensure($targetTable);
        $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

        return new DeleteMutation($targetTable, $primaryKeys);
    }
}
