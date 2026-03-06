<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Rewrite\QueryKind;
use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\CreateTableMutation;
use ZtdQuery\Shadow\Mutation\DeleteMutation;
use ZtdQuery\Shadow\Mutation\DropTableMutation;
use ZtdQuery\Shadow\Mutation\InsertMutation;
use ZtdQuery\Shadow\Mutation\ReplaceMutation;
use ZtdQuery\Shadow\Mutation\ShadowMutation;
use ZtdQuery\Shadow\Mutation\UpdateMutation;
use ZtdQuery\Shadow\Mutation\UpsertMutation;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Resolves the appropriate ShadowMutation for a given SQLite SQL statement.
 */
final class SqliteMutationResolver
{
    private ShadowStore $shadowStore;
    private TableDefinitionRegistry $registry;
    private SchemaParser $schemaParser;
    private SqliteParser $parser;

    public function __construct(
        ShadowStore $shadowStore,
        TableDefinitionRegistry $registry,
        SchemaParser $schemaParser,
        SqliteParser $parser
    ) {
        $this->shadowStore = $shadowStore;
        $this->registry = $registry;
        $this->schemaParser = $schemaParser;
        $this->parser = $parser;
    }

    /**
     * Resolve mutation for a given SQL statement.
     *
     * @throws UnsupportedSqlException
     * @throws UnknownSchemaException
     */
    public function resolve(string $sql, QueryKind $kind): ?ShadowMutation
    {
        $type = $this->parser->classifyStatement($sql);
        if ($type === null) {
            return null;
        }

        return match ($type) {
            'UPDATE' => $this->resolveUpdate($sql),
            'DELETE' => $this->resolveDelete($sql),
            'INSERT' => $this->resolveInsert($sql),
            'CREATE_TABLE' => $this->resolveCreateTable($sql),
            'DROP_TABLE' => $this->resolveDropTable($sql),
            'ALTER_TABLE' => $this->resolveAlterTable($sql),
            default => null,
        };
    }

    private function resolveUpdate(string $sql): ShadowMutation
    {
        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve UPDATE target');
        }

        $this->shadowStore->ensure($targetTable);

        $definition = $this->registry->get($targetTable);
        $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

        return new UpdateMutation($targetTable, $primaryKeys);
    }

    private function resolveDelete(string $sql): ShadowMutation
    {
        $targetTable = $this->parser->extractTargetTable($sql);
        if ($targetTable === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve DELETE target');
        }

        $trimmed = trim($this->parser->stripComments($sql));
        $upperTrimmed = strtoupper($trimmed);

        if (preg_match('/^DELETE\s+FROM\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s;]+)\s*;?\s*$/i', $trimmed) === 1) {
        }

        $this->shadowStore->ensure($targetTable);

        $definition = $this->registry->get($targetTable);
        $existingRows = $this->shadowStore->get($targetTable);
        if ($definition === null && $existingRows === []) {
            throw new UnknownSchemaException($sql, $targetTable, 'table');
        }

        $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

        return new DeleteMutation($targetTable, $primaryKeys);
    }

    private function resolveInsert(string $sql): ShadowMutation
    {
        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve INSERT target');
        }

        if ($this->parser->isReplace($sql)) {
            $definition = $this->registry->get($tableName);
            $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

            return new ReplaceMutation($tableName, $primaryKeys);
        }

        if ($this->parser->hasOnConflict($sql)) {
            $updateColumns = [];
            /** @var array<string, string> $updateValues */
            $updateValues = [];
            $onConflictUpdates = $this->parser->extractOnConflictUpdates($sql);
            foreach ($onConflictUpdates as $colName => $value) {
                $updateColumns[] = $colName;
                $updateValues[$colName] = $value;
            }

            if ($updateColumns !== []) {
                $definition = $this->registry->get($tableName);
                $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

                return new UpsertMutation($tableName, $primaryKeys, $updateColumns, $updateValues);
            }
        }

        $isIgnore = $this->parser->isInsertIgnore($sql);

        $definition = $this->registry->get($tableName);
        $primaryKeys = $isIgnore ? ($definition !== null ? $definition->primaryKeys : []) : [];

        return new InsertMutation($tableName, $primaryKeys, $isIgnore);
    }

    private function resolveCreateTable(string $sql): ShadowMutation
    {
        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve table name');
        }

        $ifNotExists = (bool) preg_match('/\bIF\s+NOT\s+EXISTS\b/i', $sql);

        if (!$ifNotExists && $this->registry->has($tableName)) {
            throw new UnsupportedSqlException($sql, 'Table already exists');
        }

        $definition = $this->schemaParser->parse($sql);

        return new CreateTableMutation($tableName, $definition, $this->registry, $ifNotExists);
    }

    private function resolveDropTable(string $sql): ShadowMutation
    {
        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve table name');
        }

        $ifExists = (bool) preg_match('/\bIF\s+EXISTS\b/i', $sql);

        if (!$ifExists && !$this->registry->has($tableName)) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }

        return new DropTableMutation($tableName, $this->registry, $ifExists);
    }

    /**
     * @throws UnsupportedSqlException
     * @throws UnknownSchemaException
     */
    private function resolveAlterTable(string $sql): ShadowMutation
    {
        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve table name');
        }

        if (!$this->registry->has($tableName)) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }

        $upperSql = strtoupper($sql);

        if (str_contains($upperSql, 'ADD COLUMN') || preg_match('/\bADD\s+(?!"COLUMN")(?:`[^`]*`|"[^"]*"|\[[^\]]*\]|\S+)/i', $sql) === 1) {
            return $this->resolveAlterAddColumn($sql, $tableName);
        }

        if (str_contains($upperSql, 'DROP COLUMN')) {
            return $this->resolveAlterDropColumn($sql, $tableName);
        }

        if (str_contains($upperSql, 'RENAME TO')) {
            return $this->resolveAlterRenameTable($sql, $tableName);
        }

        if (preg_match('/\bRENAME\s+COLUMN\b/i', $sql) === 1 || preg_match('/\bRENAME\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\S+)\s+TO\b/i', $sql) === 1) {
            return $this->resolveAlterRenameColumn($sql, $tableName);
        }

        throw new UnsupportedSqlException($sql, 'Unsupported ALTER TABLE operation');
    }

    private function resolveAlterAddColumn(string $sql, string $tableName): ShadowMutation
    {
        if (preg_match('/\bADD\s+(?:COLUMN\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)\s*(.*?)$/is', $sql, $matches) !== 1) {
            throw new UnsupportedSqlException($sql, 'Cannot parse ADD COLUMN');
        }

        $colName = $this->parser->unquoteIdentifier($matches[1]);
        $existingDef = $this->registry->get($tableName);
        if ($existingDef === null) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }


        $newColumns = array_merge($existingDef->columns, [$colName]);
        $newColumnTypes = $existingDef->columnTypes;
        $newTypedColumns = $existingDef->typedColumns;


        $rest = trim($matches[2]);
        if ($rest !== '') {
            $typeMatch = [];
            if (preg_match('/^([A-Za-z_]\w*(?:\s+\w+)?)(?:\s*\(([^)]*)\))?/i', $rest, $typeMatch) === 1) {
                $typeName = strtoupper(trim($typeMatch[1]));
                $firstWord = explode(' ', $typeName)[0];
                $nonTypeKeywords = ['PRIMARY', 'NOT', 'UNIQUE', 'CHECK', 'DEFAULT', 'REFERENCES', 'CONSTRAINT', 'COLLATE', 'GENERATED', 'AS'];
                if (!in_array($firstWord, $nonTypeKeywords, true)) {
                    if (isset($typeMatch[2]) && $typeMatch[2] !== '') {
                        $typeName .= '(' . $typeMatch[2] . ')';
                    }
                    $newColumnTypes[$colName] = $typeName;
                    $newTypedColumns[$colName] = new ColumnType(ColumnTypeFamily::UNKNOWN, $typeName);
                }
            }
        }

        $newDef = new \ZtdQuery\Schema\TableDefinition(
            $newColumns,
            $newColumnTypes,
            $existingDef->primaryKeys,
            $existingDef->notNullColumns,
            $existingDef->uniqueConstraints,
            $newTypedColumns,
        );


        return new \ZtdQuery\Shadow\Mutation\CreateTableMutation($tableName, $newDef, $this->registry, true);
    }

    private function resolveAlterDropColumn(string $sql, string $tableName): ShadowMutation
    {
        if (preg_match('/\bDROP\s+COLUMN\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s;]+)/i', $sql, $matches) !== 1) {
            throw new UnsupportedSqlException($sql, 'Cannot parse DROP COLUMN');
        }

        $colName = $this->parser->unquoteIdentifier($matches[1]);
        $existingDef = $this->registry->get($tableName);
        if ($existingDef === null) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }

        $newColumns = array_values(array_filter($existingDef->columns, static fn (string $c): bool => $c !== $colName));
        $newColumnTypes = $existingDef->columnTypes;
        unset($newColumnTypes[$colName]);
        $newTypedColumns = $existingDef->typedColumns;
        unset($newTypedColumns[$colName]);
        $newNotNull = array_values(array_filter($existingDef->notNullColumns, static fn (string $c): bool => $c !== $colName));
        $newPrimaryKeys = array_values(array_filter($existingDef->primaryKeys, static fn (string $c): bool => $c !== $colName));
        $newUniqueConstraints = [];
        foreach ($existingDef->uniqueConstraints as $name => $cols) {
            $filtered = array_values(array_filter($cols, static fn (string $c): bool => $c !== $colName));
            if ($filtered !== []) {
                $newUniqueConstraints[$name] = $filtered;
            }
        }

        $newDef = new \ZtdQuery\Schema\TableDefinition(
            $newColumns,
            $newColumnTypes,
            $newPrimaryKeys,
            $newNotNull,
            $newUniqueConstraints,
            $newTypedColumns,
        );

        return new \ZtdQuery\Shadow\Mutation\CreateTableMutation($tableName, $newDef, $this->registry, true);
    }

    private function resolveAlterRenameTable(string $sql, string $tableName): ShadowMutation
    {
        if (preg_match('/\bRENAME\s+TO\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s;]+)/i', $sql, $matches) !== 1) {
            throw new UnsupportedSqlException($sql, 'Cannot parse RENAME TO');
        }

        $newName = $this->parser->unquoteIdentifier($matches[1]);
        $existingDef = $this->registry->get($tableName);


        return new \ZtdQuery\Shadow\Mutation\DropTableMutation($tableName, $this->registry, true);
    }

    private function resolveAlterRenameColumn(string $sql, string $tableName): ShadowMutation
    {

        if (preg_match('/\bRENAME\s+(?:COLUMN\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s]+)\s+TO\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s;]+)/i', $sql, $matches) !== 1) {
            throw new UnsupportedSqlException($sql, 'Cannot parse RENAME COLUMN');
        }

        $oldName = $this->parser->unquoteIdentifier($matches[1]);
        $newName = $this->parser->unquoteIdentifier($matches[2]);
        $existingDef = $this->registry->get($tableName);
        if ($existingDef === null) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }


        $newColumns = array_map(static fn (string $c): string => $c === $oldName ? $newName : $c, $existingDef->columns);
        $newColumnTypes = [];
        foreach ($existingDef->columnTypes as $col => $type) {
            $newColumnTypes[$col === $oldName ? $newName : $col] = $type;
        }
        $newTypedColumns = [];
        foreach ($existingDef->typedColumns as $col => $type) {
            $newTypedColumns[$col === $oldName ? $newName : $col] = $type;
        }
        $newNotNull = array_map(static fn (string $c): string => $c === $oldName ? $newName : $c, $existingDef->notNullColumns);
        $newPrimaryKeys = array_map(static fn (string $c): string => $c === $oldName ? $newName : $c, $existingDef->primaryKeys);
        $newUniqueConstraints = [];
        foreach ($existingDef->uniqueConstraints as $name => $cols) {
            $newUniqueConstraints[$name] = array_map(static fn (string $c): string => $c === $oldName ? $newName : $c, $cols);
        }

        $newDef = new \ZtdQuery\Schema\TableDefinition(
            $newColumns,
            $newColumnTypes,
            $newPrimaryKeys,
            $newNotNull,
            $newUniqueConstraints,
            $newTypedColumns,
        );

        return new \ZtdQuery\Shadow\Mutation\CreateTableMutation($tableName, $newDef, $this->registry, true);
    }
}
