<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Transformer;

use InvalidArgumentException;
use ZtdQuery\Rewrite\InsertRowProjectionPlanner;

/**
 * Builds INSERT row expressions using supplied values, defaults and generated identities.
 */
final class InsertRowRenderer
{
    private InsertRowProjectionPlanner $projectionPlanner;

    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct()
    {
        $this->projectionPlanner = new InsertRowProjectionPlanner();
    }

    /**
     * @param list<string> $insertColumns
     * @param list<string> $values
     * @return array<string, string>
     * @throws InvalidArgumentException
     */
    public function providedExpressions(array $insertColumns, array $values): array
    {
        if (count($insertColumns) !== count($values)) {
            throw new InvalidArgumentException('Insert values count does not match column count.');
        }

        $provided = [];
        foreach ($insertColumns as $index => $column) {
            $expression = trim($values[$index]);
            if (strcasecmp($expression, 'DEFAULT') !== 0) {
                $provided[$column] = $expression;
            }
        }

        return $provided;
    }

    /**
     * @param list<string> $tableColumns
     * @param array<string, string> $providedExpressions
     * @param array<string, string> $defaults
     * @param array<string, int> $generatedIdentityValues
     * @return array<string, string>
     */
    public function render(
        array $tableColumns,
        array $providedExpressions,
        array $defaults,
        array $generatedIdentityValues = [],
    ): array {
        $rendered = [];
        foreach ($this->projectionPlanner->plan($tableColumns, $providedExpressions, $defaults, $generatedIdentityValues) as $projection) {
            $rendered[$projection->targetColumn()] = $projection->providedExpression()
                ?? $projection->defaultExpressionValue()
                ?? ($projection->generatedIdentityValue() !== null ? (string) $projection->generatedIdentityValue() : 'NULL');
        }

        return $rendered;
    }
}
