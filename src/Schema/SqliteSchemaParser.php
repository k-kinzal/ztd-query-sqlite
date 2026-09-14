<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema;

use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Schema\TableDefinition;

/**
 * SQLite implementation of SchemaParser.
 *
 * Parses CREATE TABLE statements while preserving nested SQL expressions.
 * @visibility public
 * @example Inspect a CREATE TABLE definition
 *     $schema = (new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser())->parse('CREATE TABLE users(id INTEGER PRIMARY KEY)');
 *     $schema?->primaryKeys // => ['id']
 */
final class SqliteSchemaParser implements SchemaParser
{
    /**
     * {@inheritDoc}
     *
     * @visibility public
     * @example Reflect declared columns and primary keys from SQLite DDL
     *     $schema = (new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser())->parse('CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT)');
     *     $schema?->columns // => ['id', 'name']
     *     $schema?->primaryKeys // => ['id']
     */
    public function parse(string $createTableSql): ?TableDefinition
    {
        $trimmed = trim($createTableSql);

        $body = (new Create\TableBodyParser())->tableBody($trimmed);
        if ($body === null) {
            return (new Create\VirtualTableParser())->parseFts5VirtualTable($trimmed);
        }

        $builder = new Create\TableDefinitionBuilder();
        foreach ((new Create\TableBodyParser())->splitColumnDefinitions($body) as $definition) {
            $builder->addDefinition($definition);
        }

        return $builder->build($createTableSql);
    }

}
