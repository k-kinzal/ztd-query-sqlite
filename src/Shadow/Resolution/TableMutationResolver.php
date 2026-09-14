<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Resolution;

use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\ShadowMutation;
use ZtdQuery\Shadow\Mutation\Table\CreateTableMutation;
use ZtdQuery\Shadow\Mutation\Table\DropTableMutation;

/**
 * Resolves table lifecycle and ALTER operations.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class TableMutationResolver
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private SqliteParser $parser,
        private TableDefinitionRegistry $registry,
        private SchemaParser $schemaParser
    ) {
    }

    /**
     * Resolves table lifecycle and ALTER operations.
     * @throws UnsupportedSqlException
     */
    public function resolveCreateTable(string $sql): ShadowMutation
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

        return new CreateTableMutation($tableName, $definition, $this->registry, $sql, $ifNotExists);
    }

    /**
     * Resolves table lifecycle and ALTER operations.
     * @throws UnknownSchemaException
     * @throws UnsupportedSqlException
     */
    public function resolveDropTable(string $sql): ShadowMutation
    {
        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve table name');
        }

        $ifExists = (bool) preg_match('/\bIF\s+EXISTS\b/i', $sql);

        if ($this->registry->isRemoved($tableName)) {
            if ($ifExists) {
                return new DropTableMutation($tableName, $this->registry, $sql, true);
            }
            throw new UnsupportedSqlException($sql, 'Table was removed from the virtual schema');
        }

        if (!$ifExists && !$this->registry->has($tableName)) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }

        return new DropTableMutation($tableName, $this->registry, $sql, $ifExists);
    }

    /**
     * @throws UnsupportedSqlException
     * @throws UnknownSchemaException
     */
    public function resolveAlterTable(string $sql): ShadowMutation
    {
        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve table name');
        }

        (new MutationTableLookup($this->registry))->assertTableWasNotRemoved($sql, $tableName);

        if (!$this->registry->has($tableName)) {
            throw new UnknownSchemaException($sql, $tableName, 'table');
        }

        $operation = (new \ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser())->alterOperation($sql);
        if ($operation === null) {
            throw new UnsupportedSqlException($sql, 'Unsupported ALTER TABLE operation');
        }

        return match ($operation['kind']) {
            'add' => (new \ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\AddColumnResolver($this->registry, $this->schemaParser))->resolveAlterAddColumn($sql, $tableName, $operation['clause']),
            'drop' => (new \ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\DropColumnResolver($this->registry))->resolveAlterDropColumn($sql, $tableName, $operation['clause']),
            'rename_table' => (new \ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\RenameResolver($this->registry))->resolveAlterRenameTable($sql, $tableName, $operation['clause']),
            'rename_column' => (new \ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\RenameResolver($this->registry))->resolveAlterRenameColumn($sql, $tableName, $operation['clause']),
        };
    }
}
