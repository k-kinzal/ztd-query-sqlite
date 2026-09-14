<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter;

use ZtdQuery\Exception\ColumnNotFoundException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Schema\Key\ForeignKeyDefinition;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\ShadowMutation;

/**
 * Builds a schema and row projection for removing a column.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class DropColumnResolver
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private TableDefinitionRegistry $registry
    ) {
    }

    /**
     * Builds a schema and row projection for removing a column.
     * @throws ColumnNotFoundException
     * @throws UnsupportedSqlException
     */
    public function resolveAlterDropColumn(string $sql, string $tableName, string $columnClause): ShadowMutation
    {
        $requestedName = (new \ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser())->singleIdentifier($columnClause);
        if ($requestedName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot parse DROP COLUMN');
        }

        $existing = (new \ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup($this->registry))->definition($sql, $tableName);
        $columnName = (new AlteredTableProjection($this->registry))->existingColumn($existing, $requestedName);
        if ($columnName === null) {
            throw new ColumnNotFoundException($sql, $tableName, $requestedName);
        }

        $definition = $this->definitionWithoutColumn($existing, $columnName);

        return (new AlteredTableProjection($this->registry))->alterMutation(
            $sql,
            $tableName,
            $tableName,
            $definition,
            (new AlteredTableProjection($this->registry))->quotedColumns($definition->columns),
        );
    }

    /**
     * Removes a column and all direct key/type/default metadata referring to it.
     */
    public function definitionWithoutColumn(TableDefinition $existing, string $columnName): TableDefinition
    {
        $newColumns = ColumnDefinitionEditor::withoutColumn($existing->columns, $columnName);
        $newColumnTypes = ColumnDefinitionEditor::withoutMapKey($existing->columnTypes, $columnName);
        $newTypedColumns = ColumnDefinitionEditor::withoutMapKey($existing->typedColumns, $columnName);
        $newDefaults = ColumnDefinitionEditor::withoutMapKey($existing->columnDefaults, $columnName);
        $newIdentityStrategies = ColumnDefinitionEditor::withoutMapKey($existing->identityStrategies, $columnName);
        $newGeneratedExpressions = ColumnDefinitionEditor::withoutMapKey($existing->generatedExpressions, $columnName);
        $newForeignKeys = array_filter(
            $existing->foreignKeys,
            static fn (ForeignKeyDefinition $foreignKey): bool => !in_array(
                $columnName,
                $foreignKey->columns,
                true,
            ),
        );
        $newNotNull = ColumnDefinitionEditor::withoutColumn($existing->notNullColumns, $columnName);
        $newPrimaryKeys = ColumnDefinitionEditor::withoutColumn($existing->primaryKeys, $columnName);
        $newUniqueConstraints = [];
        foreach ($existing->uniqueConstraints as $name => $columns) {
            $filtered = ColumnDefinitionEditor::withoutColumn($columns, $columnName);
            if ($filtered !== []) {
                $newUniqueConstraints[$name] = $filtered;
            }
        }

        return new TableDefinition(
            $newColumns,
            $newColumnTypes,
            $newPrimaryKeys,
            $newNotNull,
            $newUniqueConstraints,
            $newTypedColumns,
            $newDefaults,
            $newIdentityStrategies,
            $newGeneratedExpressions,
            $newForeignKeys,
        );

    }
}
