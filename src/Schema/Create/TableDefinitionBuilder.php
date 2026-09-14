<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\Create;

use ZtdQuery\Platform\Sqlite\Schema\SqliteColumnTypeMapper;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\Key\IdentityGenerationStrategy;
use ZtdQuery\Schema\TableDefinition;

/**
 * Accumulates column and key declarations in their original CREATE TABLE order.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class TableDefinitionBuilder
{
    /**
     * @var list<string>
     */
    private array $columns = [];

    /**
     * @var array<string, string>
     */
    private array $columnTypes = [];

    /**
     * @var array<string, string>
     */
    private array $primaryKeyMap = [];

    /**
     * @var list<string>
     */
    private array $notNullColumns = [];

    /**
     * @var array<string, list<string>>
     */
    private array $uniqueConstraints = [];

    /**
     * @var array<string, string>
     */
    private array $columnDefaults = [];

    /**
     * @var array<string, string>
     */
    private array $generatedExpressions = [];

    private int $uniqueIndex = 0;

    /**
     * Adds one column or table constraint, ignoring unsupported table constraints.
     */
    public function addDefinition(string $definition): void
    {
        $definition = trim($definition);
        if ($definition === '') {
            return;
        }
        $parser = new ColumnDefinitionParser();
        $keyword = $parser->leadingKeyword($definition);
        if (in_array($keyword, ['PRIMARY', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
            $this->addConstraint($definition);
            return;
        }
        $column = $parser->parseColumnDefinition($definition);
        if ($column !== null) {
            $this->addColumn($column);
        }
    }

    /**
     * Records table-level PRIMARY KEY and UNIQUE declarations.
     */
    public function addConstraint(string $definition): void
    {
        $prefix = '(?:CONSTRAINT\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|[^\s]+)\s+)?';
        if (preg_match('/^' . $prefix . 'PRIMARY\s+KEY\s*\(([^)]+)\)/i', $definition, $matches) === 1) {
            foreach ((new ColumnDefinitionParser())->parseColumnNameList($matches[1]) as $column) {
                $this->primaryKeyMap[$column] = $column;
            }
        }
        if (preg_match('/^' . $prefix . 'UNIQUE\s*\(([^)]+)\)/i', $definition, $matches) === 1) {
            $columns = (new ColumnDefinitionParser())->parseColumnNameList($matches[1]);
            if ($columns !== []) {
                $this->uniqueConstraints['unique_' . $this->uniqueIndex++] = $columns;
            }
        }
    }

    /**
     * @param array{name: string, type: string|null, notNull: bool, primaryKey: bool, unique: bool, default: string|null, generatedExpression: string|null} $colInfo
     */
    public function addColumn(array $colInfo): void
    {
        $this->columns[] = $colInfo['name'];

        if ($colInfo['type'] !== null) {
            $this->columnTypes[$colInfo['name']] = $colInfo['type'];
        }

        if ($colInfo['notNull']) {
            $this->notNullColumns[] = $colInfo['name'];
        }

        if ($colInfo['primaryKey']) {
            $this->primaryKeyMap[$colInfo['name']] = $colInfo['name'];
            if (!in_array($colInfo['name'], $this->notNullColumns, true)) {
                $this->notNullColumns[] = $colInfo['name'];
            }
        }

        if ($colInfo['unique']) {
            $keyName = $colInfo['name'] . '_UNIQUE';
            $this->uniqueConstraints[$keyName] = [$colInfo['name']];
        }

        if ($colInfo['default'] !== null) {
            $this->columnDefaults[$colInfo['name']] = $colInfo['default'];
        }
        if ($colInfo['generatedExpression'] !== null) {
            $this->generatedExpressions[$colInfo['name']] = $colInfo['generatedExpression'];
        }
    }

    /**
     * Validates referenced columns and produces a complete portable table definition.
     */
    public function build(string $sql): ?TableDefinition
    {
        if ($this->columns === []) {
            return null;
        }

        foreach ($this->uniqueConstraints as $constraintColumns) {
            foreach ($constraintColumns as $col) {
                if (!in_array($col, $this->columns, true)) {
                    return null;
                }
            }
        }

        /**
         * @var array<string, ColumnDeclaration> $typedColumns
         */
        $typedColumns = [];
        foreach ($this->columnTypes as $colName => $nativeType) {
            $typedColumns[$colName] = (new SqliteColumnTypeMapper())->map($nativeType);
        }

        $primaryKeys = array_values($this->primaryKeyMap);
        $identityStrategies = [];
        if (!TableBodyParser::hasWithoutRowid($sql) && count($primaryKeys) === 1) {
            $identityColumn = $primaryKeys[0];
            if (($this->columnTypes[$identityColumn] ?? null) === 'INTEGER') {
                $identityStrategies[$identityColumn] = IdentityGenerationStrategy::MaxValue;
            }
        }

        return new TableDefinition(
            $this->columns,
            $this->columnTypes,
            $primaryKeys,
            array_values(array_unique($this->notNullColumns)),
            $this->uniqueConstraints,
            $typedColumns,
            $this->columnDefaults,
            $identityStrategies,
            $this->generatedExpressions,
            (new \ZtdQuery\Platform\Sqlite\Schema\Key\SqliteForeignKeyDefinitionParser())->parseCreateTable($sql),
        );
    }
}
