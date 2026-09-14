<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter;

use ZtdQuery\Exception\ColumnAlreadyExistsException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\ShadowMutation;

/**
 * Builds a schema and row projection for adding a column.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class AddColumnResolver
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private TableDefinitionRegistry $registry,
        private SchemaParser $schemaParser
    ) {
    }

    /**
     * Builds a schema and row projection for adding a column.
     * @throws ColumnAlreadyExistsException
     * @throws UnsupportedSqlException
     */
    public function resolveAlterAddColumn(string $sql, string $tableName, string $columnSql): ShadowMutation
    {
        $existing = (new \ZtdQuery\Platform\Sqlite\Shadow\Resolution\MutationTableLookup($this->registry))->definition($sql, $tableName);
        $added = $this->schemaParser->parse('CREATE TABLE "__ztd_alter" (' . $columnSql . ')');
        if ($added === null) {
            throw new UnsupportedSqlException($sql, 'Cannot parse ADD COLUMN');
        }
        if (count($added->columns) !== 1) {
            throw new UnsupportedSqlException($sql, 'Cannot parse ADD COLUMN');
        }

        $columnName = $added->columns[0];
        if ((new AlteredTableProjection($this->registry))->existingColumn($existing, $columnName) !== null) {
            throw new ColumnAlreadyExistsException($sql, $tableName, $columnName);
        }

        $foreignKeys = $existing->foreignKeys;
        foreach ($added->foreignKeys as $name => $foreignKey) {
            while (isset($foreignKeys[$name])) {
                $name .= '_added';
            }
            $foreignKeys[$name] = $foreignKey;
        }

        $definition = new TableDefinition(
            [...$existing->columns, $columnName],
            array_merge($existing->columnTypes, $added->columnTypes),
            $existing->primaryKeys,
            [...$existing->notNullColumns, ...$added->notNullColumns],
            $existing->uniqueConstraints,
            array_merge($existing->typedColumns, $added->typedColumns),
            array_merge($existing->columnDefaults, $added->columnDefaults),
            $existing->identityStrategies,
            array_merge($existing->generatedExpressions, $added->generatedExpressions),
            $foreignKeys,
        );
        $projection = (new AlteredTableProjection($this->registry))->quotedColumns($existing->columns);
        $projection[] = ($added->columnDefaults[$columnName] ?? 'NULL')
            . ' AS ' . (new AlteredTableProjection($this->registry))->quote($columnName);

        return (new AlteredTableProjection($this->registry))->alterMutation($sql, $tableName, $tableName, $definition, $projection);
    }
}
