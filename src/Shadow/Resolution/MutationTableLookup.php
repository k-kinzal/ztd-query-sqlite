<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Resolution;

use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;

/**
 * Checks shadow table lifecycle and resolves required schema definitions.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class MutationTableLookup
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private TableDefinitionRegistry $registry
    ) {
    }

    /**
     * Checks shadow table lifecycle and resolves required schema definitions.
     * @throws UnknownSchemaException
     */
    public function definition(string $sql, string $tableName): TableDefinition
    {
        $definition = $this->registry->get($tableName);
        if ($definition === null) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }

        return $definition;
    }

    /**
     * Checks shadow table lifecycle and resolves required schema definitions.
     * @throws UnsupportedSqlException
     */
    public function assertTableWasNotRemoved(string $sql, string $tableName): void
    {
        if ($this->registry->isRemoved($tableName)) {
            throw new UnsupportedSqlException($sql, 'Table was removed from the virtual schema');
        }
    }
}
