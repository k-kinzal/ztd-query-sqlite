<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Upsert;

use ZtdQuery\Platform\IdentifierQuoter;
use ZtdQuery\Shadow\Mutation\UpsertMutationRow;

/**
 * Builds SQL projections that evaluate upsert expressions using SQLite.
 */
final class SqliteNativeUpsertProjector
{
    private const INCOMING_ALIAS = '__ztd_incoming';

    private const EXISTING_ALIAS = '__ztd_existing';

    private readonly IdentifierQuoter $quoter;

    /**
     * @var non-empty-list<string>
     */
    private readonly array $incomingNamespaces;

    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct()
    {
        $this->quoter = new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter();
        $this->incomingNamespaces = ['EXCLUDED'];
    }

    /**
     * @param list<string> $tableColumns
     * @param array<string, array<int, string>> $candidateKeys
     * @param array<string, string> $assignments
     */
    public function project(
        string $incomingSql,
        string $tableName,
        array $tableColumns,
        array $candidateKeys,
        array $assignments,
        ?string $predicate = null,
        ?string $conflictPredicate = null,
    ): string {
        if ($assignments === [] || $candidateKeys === []) {
            return $incomingSql;
        }

        $binder = new UpsertExpressionBinder($this->incomingNamespaces, $this->quoter);
        $incomingAlias = $this->quoter->quote(self::INCOMING_ALIAS);
        $existingAlias = $this->quoter->quote(self::EXISTING_ALIAS);
        $table = $this->quoter->quote($tableName);
        $conflict = (new UpsertConflictPredicate($this->quoter))->conflictPredicate($candidateKeys, $existingAlias, $incomingAlias);
        if ($conflictPredicate !== null) {
            $existingPredicate = $binder->bindExpression($conflictPredicate, $tableName, $tableColumns, self::EXISTING_ALIAS);
            $incomingPredicate = $binder->bindExpression($conflictPredicate, $tableName, $tableColumns, self::INCOMING_ALIAS);
            $conflict = "($conflict AND ($existingPredicate) AND ($incomingPredicate))";
        }
        $selects = [];
        foreach ($tableColumns as $column) {
            $quoted = $this->quoter->quote($column);
            $selects[] = "$incomingAlias.$quoted AS $quoted";
        }

        $codec = new UpsertMutationRow();
        foreach (array_values($assignments) as $index => $expression) {
            $evaluated = $binder->bindExpression($expression, $tableName, $tableColumns);
            $metadata = $this->quoter->quote($codec->valueColumn($index));
            $selects[] = "(SELECT $evaluated FROM $table AS $existingAlias WHERE $conflict LIMIT 1) AS $metadata";
        }
        if ($predicate !== null) {
            $evaluated = $binder->bindExpression($predicate, $tableName, $tableColumns);
            $metadata = $this->quoter->quote($codec->predicateColumn());
            $selects[] = "(SELECT $evaluated FROM $table AS $existingAlias WHERE $conflict LIMIT 1) AS $metadata";
        }

        return 'SELECT ' . implode(', ', $selects) . " FROM ($incomingSql) AS $incomingAlias";
    }

}
