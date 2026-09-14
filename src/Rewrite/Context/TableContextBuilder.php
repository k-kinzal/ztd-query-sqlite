<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Context;

use ZtdQuery\Platform\Sqlite\Rewrite\View\SqliteViewShadowRenderer;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinitionSet;

/**
 * Collects shadow rows, schemas and views into the rewrite context.
 *
 * @template TValue
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class TableContextBuilder
{
    /**
     * Snapshots the fixtures whose values will be forwarded to platform renderers.
     *
     * @param array<string, array<int, array<string, TValue>>> $allData
     */
    public function __construct(
        private TableDefinitionRegistry $registry,
        private array $allData,
        private ViewDefinitionSet $views
    ) {
    }

    /**
     * Build the table context map for transformers.
     *
     * @return array<string, array{viewSql: string}|array{
     *     rows: array<int, array<string, TValue>>,
     *     columns: array<int, string>,
     *     columnTypes: array<string, \ZtdQuery\Schema\ColumnDeclaration>,
     *     columnDefaults: array<string, string>,
     *     identityStrategies: array<string, \ZtdQuery\Schema\Key\IdentityGenerationStrategy>,
     *     generatedExpressions: array<string, string>,
     *     primaryKeys: array<int, string>,
     *     candidateKeys: array<string, array<int, string>>
     * }>
     */
    public function buildTableContext(): array
    {
        $context = [];

        foreach ($this->allData as $tableName => $rows) {
            $definition = $this->registry->get($tableName);
            if ($definition !== null) {
                $context[$tableName] = self::contextFromDefinition($definition, $rows);
            } else {
                $columns = self::columnsFromRows($rows);

                $context[$tableName] = [
                    'rows' => $rows,
                    'columns' => $columns,
                    'columnTypes' => [],
                    'columnDefaults' => [],
                    'identityStrategies' => [],
                    'generatedExpressions' => [],
                    'primaryKeys' => [],
                    'candidateKeys' => [],
                ];
            }
        }

        $allDefinitions = $this->registry->getAll();
        foreach ($allDefinitions as $tableName => $definition) {
            if (isset($context[$tableName])) {
                continue;
            }

            $context[$tableName] = self::contextFromDefinition($definition, []);
        }
        foreach ($this->registry->getAllRemoved() as $tableName => $definition) {
            $context[$tableName] = self::contextFromDefinition($definition, []);
        }

        foreach ((new SqliteViewShadowRenderer())->render($this->views, array_keys($context)) as $viewName => $viewSql) {
            if (isset($context[$viewName])) {
                continue;
            }
            $context[$viewName] = ['viewSql' => $viewSql];
        }

        return $context;
    }

    /**
     * @template TRowValue = never
     * @param array<int, array<string, TRowValue>> $rows
     * @return array{
     *     rows: array<int, array<string, TRowValue>>,
     *     columns: array<int, string>,
     *     columnTypes: array<string, \ZtdQuery\Schema\ColumnDeclaration>,
     *     columnDefaults: array<string, string>,
     *     identityStrategies: array<string, \ZtdQuery\Schema\Key\IdentityGenerationStrategy>,
     *     generatedExpressions: array<string, string>,
     *     primaryKeys: array<int, string>,
     *     candidateKeys: array<string, array<int, string>>
     * }
     */
    public static function contextFromDefinition(TableDefinition $definition, array $rows): array
    {
        return [
            'rows' => $rows,
            'columns' => $definition->columns,
            'columnTypes' => $definition->typedColumns,
            'columnDefaults' => $definition->columnDefaults,
            'identityStrategies' => $definition->identityStrategies,
            'generatedExpressions' => $definition->generatedExpressions,
            'primaryKeys' => $definition->primaryKeys,
            'candidateKeys' => $definition->candidateKeys()->keys(),
        ];
    }
    /**
     * Collects columns in their first-seen order across heterogeneous fixture rows.
     *
     * @template TRowValue = never
     * @param array<int, array<string, TRowValue>> $rows
     * @return list<string>
     */
    public static function columnsFromRows(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

}
