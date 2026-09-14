<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;

/**
 * Builds an ALTER mutation from its projected schema and rows.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class AlteredTableProjection
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private TableDefinitionRegistry $registry
    ) {
    }

    /**
     * @throws UnsupportedSqlException
     * @param list<string> $projection
     */
    public function alterMutation(
        string $sql,
        string $sourceTable,
        string $targetTable,
        TableDefinition $definition,
        array $projection,
    ): AlterTableMutation {
        if ($projection === []) {
            throw new UnsupportedSqlException($sql, 'ALTER TABLE would remove every column');
        }

        return new AlterTableMutation(
            $sql,
            $sourceTable,
            $targetTable,
            $definition,
            $this->registry,
            'SELECT ' . implode(', ', $projection) . ' FROM ' . $this->quote($sourceTable),
        );
    }

    /**
     * Builds an ALTER mutation from its projected schema and rows.
     */
    public function existingColumn(TableDefinition $definition, string $requested): ?string
    {
        foreach ($definition->columns as $column) {
            if (strcasecmp($column, $requested) === 0) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $columns
     * @return list<string>
     */
    public function quotedColumns(array $columns): array
    {
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = $this->quote($column);
        }

        return $quoted;
    }

    /**
     * Builds an ALTER mutation from its projected schema and rows.
     */
    public function quote(string $identifier): string
    {
        return (new SqliteIdentifierQuoter())->quote($identifier);
    }
}
