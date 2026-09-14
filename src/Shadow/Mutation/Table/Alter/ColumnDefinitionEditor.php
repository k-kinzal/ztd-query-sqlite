<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter;

use ZtdQuery\Schema\Key\ForeignKeyDefinition;

/**
 * Preserves dependent schema metadata while removing or renaming a column.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ColumnDefinitionEditor
{
    /**
     * @param array<int, string> $columns
     * @return list<string>
     */
    public static function withoutColumn(array $columns, string $removed): array
    {
        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== $removed,
        ));
    }

    /**
     * @template T
     * @param array<string, T> $map
     * @return array<string, T>
     */
    public static function withoutMapKey(array $map, string $removed): array
    {
        unset($map[$removed]);

        return $map;
    }

    /**
     * @param array<int, string> $columns
     * @return list<string>
     */
    public static function renamedColumns(array $columns, string $old, string $new): array
    {
        $renamed = [];
        foreach ($columns as $column) {
            $renamed[] = $column === $old ? $new : $column;
        }

        return $renamed;
    }

    /**
     * Preserves dependent schema metadata while removing or renaming a column.
     */
    public static function renamedForeignKey(
        ForeignKeyDefinition $foreignKey,
        string $old,
        string $new,
    ): ForeignKeyDefinition {
        $columns = self::renamedColumns($foreignKey->columns, $old, $new);
        if ($columns === []) {
            return $foreignKey;
        }

        return new ForeignKeyDefinition(
            $columns,
            $foreignKey->referencedTable,
            $foreignKey->referencedColumns,
            $foreignKey->onDelete,
            $foreignKey->onUpdate,
        );
    }

    /**
     * @template T
     * @param array<string, T> $map
     * @return array<string, T>
     */
    public static function renamedMapKey(array $map, string $old, string $new): array
    {
        $renamed = [];
        foreach ($map as $column => $value) {
            $renamed[$column === $old ? $new : $column] = $value;
        }

        return $renamed;
    }
}
