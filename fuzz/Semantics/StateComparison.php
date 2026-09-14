<?php

declare(strict_types=1);

namespace Fuzz\Semantics;

use Error;
use PDO;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Checks native results, shadow rows and physical isolation for a command sequence.
 */
final class StateComparison
{
    /**
     * Executes a query while treating connection failures as campaign failures.
     *
     * @return list<array<string, mixed>>
     * @throws Error
     */
    public static function query(PDO $connection, string $sql): array
    {
        $statement = $connection->query($sql);
        if ($statement === false) {
            throw new Error('SQLite query failed: ' . $sql);
        }

        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new Error('SQLite returned a non-associative row');
            }
            $columns = [];
            foreach ($row as $column => $value) {
                if (!is_string($column)) {
                    throw new Error('SQLite returned an unnamed result column');
                }
                $columns[$column] = $value;
            }
            $rows[] = $columns;
        }

        return $rows;
    }

    /**
     * Checks row values and physical isolation after every command, including no-op writes.
     *
     * @throws Error
     */
    public static function compare(PDO $nativeDatabase, PDO $physicalDatabase, ShadowStore $store): void
    {
        $native = self::query($nativeDatabase, 'SELECT id, name, score FROM users ORDER BY id');
        $shadow = $store->get('users');
        array_multisort(array_column($shadow, 'id'), SORT_ASC, SORT_NUMERIC, $shadow);
        $native = array_map(static function (array $row): array {
            ksort($row);
            return $row;
        }, $native);
        $shadow = array_map(static function (array $row): array {
            ksort($row);
            return $row;
        }, $shadow);
        if ($native !== $shadow) {
            throw new Error('Native/shadow state mismatch: ' . var_export([$native, $shadow], true));
        }
        $physical = self::query($physicalDatabase, 'SELECT id, name, score FROM users');
        if ($physical !== [['id' => 9000, 'name' => 'physical', 'score' => 777]]) {
            throw new Error('Physical database was modified: ' . var_export($physical, true));
        }
    }
}
