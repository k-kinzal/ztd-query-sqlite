<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter;

use ZtdQuery\Exception\ColumnAlreadyExistsException;
use ZtdQuery\Exception\ColumnNotFoundException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Schema\Key\ForeignKeyDefinition;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\ShadowMutation;

/**
 * Builds schema changes for renaming a table or column.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class RenameResolver
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private TableDefinitionRegistry $registry
    ) {
    }

    /**
     * Builds schema changes for renaming a table or column.
     * @throws UnsupportedSqlException
     */
    public function resolveAlterRenameTable(string $sql, string $tableName, string $tableClause): ShadowMutation
    {
        $newName = (new \ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser())->singleIdentifier($tableClause);
        if ($newName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot parse RENAME TO');
        }

        $existing = (new \ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup($this->registry))->definition($sql, $tableName);

        return (new AlteredTableProjection($this->registry))->alterMutation(
            $sql,
            $tableName,
            $newName,
            $existing,
            (new AlteredTableProjection($this->registry))->quotedColumns($existing->columns),
        );
    }

    /**
     * Builds schema changes for renaming a table or column.
     * @throws ColumnAlreadyExistsException
     * @throws ColumnNotFoundException
     * @throws UnsupportedSqlException
     */
    public function resolveAlterRenameColumn(string $sql, string $tableName, string $columnClause): ShadowMutation
    {
        $renamed = (new \ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser())->renamedIdentifiers($columnClause);
        if ($renamed === null) {
            throw new UnsupportedSqlException($sql, 'Cannot parse RENAME COLUMN');
        }

        [$requestedName, $newName] = $renamed;
        $existing = (new \ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup($this->registry))->definition($sql, $tableName);
        $oldName = (new AlteredTableProjection($this->registry))->existingColumn($existing, $requestedName);
        if ($oldName === null) {
            throw new ColumnNotFoundException($sql, $tableName, $requestedName);
        }
        $duplicate = (new AlteredTableProjection($this->registry))->existingColumn($existing, $newName);
        if ($duplicate !== null && strcasecmp($duplicate, $oldName) !== 0) {
            throw new ColumnAlreadyExistsException($sql, $tableName, $newName);
        }

        $definition = $this->definitionWithRenamedColumn($existing, $oldName, $newName);
        $projection = [];
        foreach ($existing->columns as $column) {
            $expression = (new AlteredTableProjection($this->registry))->quote($column);
            if ($column === $oldName) {
                $expression .= ' AS ' . (new AlteredTableProjection($this->registry))->quote($newName);
            }
            $projection[] = $expression;
        }

        return (new AlteredTableProjection($this->registry))->alterMutation($sql, $tableName, $tableName, $definition, $projection);
    }

    /**
     * Renames a column consistently throughout key and column metadata.
     */
    public function definitionWithRenamedColumn(TableDefinition $existing, string $oldName, string $newName): TableDefinition
    {
        $newColumns = ColumnDefinitionEditor::renamedColumns($existing->columns, $oldName, $newName);
        $newUniqueConstraints = [];
        foreach ($existing->uniqueConstraints as $name => $columns) {
            $newUniqueConstraints[$name] = ColumnDefinitionEditor::renamedColumns($columns, $oldName, $newName);
        }

        return new TableDefinition(
            $newColumns,
            ColumnDefinitionEditor::renamedMapKey($existing->columnTypes, $oldName, $newName),
            ColumnDefinitionEditor::renamedColumns($existing->primaryKeys, $oldName, $newName),
            ColumnDefinitionEditor::renamedColumns($existing->notNullColumns, $oldName, $newName),
            $newUniqueConstraints,
            ColumnDefinitionEditor::renamedMapKey($existing->typedColumns, $oldName, $newName),
            ColumnDefinitionEditor::renamedMapKey($existing->columnDefaults, $oldName, $newName),
            ColumnDefinitionEditor::renamedMapKey($existing->identityStrategies, $oldName, $newName),
            ColumnDefinitionEditor::renamedMapKey($existing->generatedExpressions, $oldName, $newName),
            array_map(
                static fn (ForeignKeyDefinition $foreignKey): ForeignKeyDefinition => ColumnDefinitionEditor::renamedForeignKey(
                    $foreignKey,
                    $oldName,
                    $newName,
                ),
                $existing->foreignKeys,
            ),
        );
    }
}
