<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Insert;

use RuntimeException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Rewrite\ShadowIdentityAllocator;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Builds INSERT SELECT and VALUES projections in table column order.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class InsertProjectionBuilder
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private CastRenderer $castRenderer,
        private ShadowIdentityAllocator $identityAllocator,
        private InsertSelectRenderer $insertSelectRenderer,
        private SqliteParser $parser,
        private InsertRowRenderer $rowRenderer
    ) {
    }

    /**
     * @template TValue
     * @param list<string> $tableColumns
     * @param list<string> $insertColumns
     * @param array<string, ColumnDeclaration> $columnTypes
     * @param array<string, string> $columnDefaults
     * @param array<string, \ZtdQuery\Schema\Key\IdentityGenerationStrategy> $identityStrategies
     * @param array<int, array<string, TValue>> $existingRows
     * @throws RuntimeException
     */
    public function buildInsertSelect(
        string $sql,
        string $tableName,
        array $tableColumns,
        array $insertColumns,
        array $columnTypes,
        array $columnDefaults,
        array $identityStrategies,
        array $existingRows,
    ): string {
        if ($this->parser->hasInsertSelect($sql)) {
            return $this->buildSelectSource(
                $sql,
                $tableName,
                $tableColumns,
                $insertColumns,
                $columnDefaults,
                $identityStrategies,
                $existingRows,
            );
        }

        $valueSets = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->topLevelClause(['DEFAULT', 'VALUES']) !== null
            ? [[]]
            : $this->parser->extractInsertValues($sql);
        if ($valueSets !== []) {
            $rows = [];
            foreach ($valueSets as $values) {
                $sourceColumns = $insertColumns !== [] || $values === [] ? $insertColumns : $tableColumns;
                if (count($sourceColumns) !== count($values)) {
                    throw new UnsupportedSqlException($sql, 'Insert values count does not match column count');
                }
                $rows[] = $this->buildInsertRowSelect(
                    $values,
                    $tableName,
                    $tableColumns,
                    $insertColumns,
                    $columnTypes,
                    $columnDefaults,
                    $identityStrategies,
                    $existingRows,
                );
            }

            return implode(' UNION ALL ', $rows);
        }

        throw new UnsupportedSqlException($sql, 'Insert statement has no values to project');
    }

    /**
     * @param array<int, string> $values
     * @template TValue
     * @param list<string> $tableColumns
     * @param list<string> $insertColumns
     * @param array<string, ColumnDeclaration> $columnTypes
     * @param array<string, string> $columnDefaults
     * @param array<string, \ZtdQuery\Schema\Key\IdentityGenerationStrategy> $identityStrategies
     * @param array<int, array<string, TValue>> $existingRows
     * @throws RuntimeException
     */
    public function buildInsertRowSelect(
        array $values,
        string $tableName,
        array $tableColumns,
        array $insertColumns,
        array $columnTypes,
        array $columnDefaults,
        array $identityStrategies,
        array $existingRows,
    ): string {
        $values = self::orderedValues($values);
        $sourceColumns = $insertColumns !== [] || $values === [] ? $insertColumns : $tableColumns;
        if (count($sourceColumns) !== count($values)) {
            throw new RuntimeException('Insert values count does not match column count.');
        }
        $providedExpressions = $this->rowRenderer->providedExpressions($sourceColumns, $values);
        $generatedValues = $this->identityAllocator->allocateMissing(
            $tableName,
            $identityStrategies,
            array_keys($providedExpressions),
            $existingRows,
        );
        $projected = $this->rowRenderer->render($tableColumns, $providedExpressions, $columnDefaults, $generatedValues);

        $selects = [];
        foreach ($projected as $column => $expr) {
            $type = $columnTypes[$column] ?? null;
            if ($type instanceof ColumnDeclaration) {
                $expr = $this->castRenderer->renderCast($expr, $type);
            }
            $selects[] = $expr . ' AS "' . $column . '"';
        }

        return 'SELECT ' . implode(', ', $selects);
    }

    /**
     * @template T
     * @param array<array-key, T> $values
     * @return list<T>
     */
    public static function orderedValues(array $values): array
    {
        $ordered = [];
        foreach ($values as $value) {
            $ordered[] = $value;
        }

        return $ordered;
    }
    /**
     * Projects an INSERT SELECT source and allocates any omitted identities.
     *
     * @template TValue
     * @param list<string> $tableColumns
     * @param list<string> $insertColumns
     * @param array<string, string> $columnDefaults
     * @param array<string, \ZtdQuery\Schema\Key\IdentityGenerationStrategy> $identityStrategies
     * @param array<int, array<string, TValue>> $existingRows
     * @throws RuntimeException
     */
    public function buildSelectSource(
        string $sql,
        string $tableName,
        array $tableColumns,
        array $insertColumns,
        array $columnDefaults,
        array $identityStrategies,
        array $existingRows,
    ): string {
        $selectSql = $this->parser->extractInsertSelect($sql);
        if ($selectSql === null) {
            throw new UnsupportedSqlException($sql, 'Failed to extract SELECT from INSERT ... SELECT');
        }

        $sourceColumns = $insertColumns !== [] ? $insertColumns : $tableColumns;
        $generatedIdentityStarts = $this->identityAllocator->allocateSelectStarts(
            $tableName,
            $identityStrategies,
            $sourceColumns,
            $existingRows,
        );

        return $this->insertSelectRenderer->render(
            $selectSql,
            $tableColumns,
            $sourceColumns,
            $columnDefaults,
            $generatedIdentityStarts,
        );
    }

}
