<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Resolution;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\SqliteUpsertExpressionParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\Mutation\Row\InsertMutation;
use ZtdQuery\Shadow\Mutation\Row\ReplaceMutation;
use ZtdQuery\Shadow\Mutation\ShadowMutation;
use ZtdQuery\Shadow\Mutation\UpsertMutation;

/**
 * Resolves INSERT conflict behavior against candidate keys.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class InsertMutationResolver
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private SqliteParser $parser,
        private TableDefinitionRegistry $registry
    ) {
    }

    /**
     * Resolves INSERT conflict behavior against candidate keys.
     * @throws UnsupportedSqlException
     */
    public function resolveInsert(string $sql): ShadowMutation
    {
        $tableName = $this->parser->extractTargetTable($sql);
        if ($tableName === null) {
            throw new UnsupportedSqlException($sql, 'Cannot resolve INSERT target');
        }
        (new MutationTableLookup($this->registry))->assertTableWasNotRemoved($sql, $tableName);

        if ($this->parser->isReplace($sql)) {
            $definition = $this->registry->get($tableName);
            $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

            return new ReplaceMutation($tableName, $primaryKeys, $definition?->candidateKeys());
        }

        if ($this->parser->hasOnConflict($sql)) {
            return $this->resolveOnConflict($sql, $tableName);
        }

        $isIgnore = $this->parser->isInsertIgnore($sql);

        $definition = $this->registry->get($tableName);
        $primaryKeys = $isIgnore ? ($definition !== null ? $definition->primaryKeys : []) : [];

        return new InsertMutation(
            $tableName,
            $primaryKeys,
            $isIgnore,
            candidateKeys: $definition?->candidateKeys(),
        );
    }

    /**
     * Builds an upsert or do-nothing mutation using candidate-key semantics.
     */
    public function resolveOnConflict(string $sql, string $tableName): ShadowMutation
    {
        $updateColumns = [];
        $definition = $this->registry->get($tableName);
        $databaseEvaluated = $definition !== null && $definition->candidateKeys()->keys() !== [];
        /**
         * @var array<string, \ZtdQuery\Shadow\Mutation\UpsertExpression|null> $updateValues
         */
        $updateValues = [];
        $expressionParser = new SqliteUpsertExpressionParser();
        $onConflictUpdates = $this->parser->extractOnConflictUpdates($sql);
        foreach ($onConflictUpdates as $colName => $value) {
            $updateColumns[] = $colName;
            $updateValues[$colName] = $databaseEvaluated
                ? $expressionParser->parseIfSupported($value, $tableName)
                : $expressionParser->parse($value, $tableName);
        }

        if ($updateColumns !== []) {
            $primaryKeys = $definition !== null ? $definition->primaryKeys : [];
            $predicate = $this->parser->extractOnConflictUpdateWhere($sql);

            return new UpsertMutation(
                $tableName,
                $primaryKeys,
                $updateColumns,
                $updateValues,
                $definition?->candidateKeys(),
                $predicate !== null
                    ? ($databaseEvaluated
                        ? $expressionParser->parseIfSupported($predicate, $tableName)
                        : $expressionParser->parse($predicate, $tableName))
                    : null,
                databaseEvaluated: $databaseEvaluated,
                updateSqlValues: $onConflictUpdates,
                updateSqlPredicate: $predicate,
            );
        }

        $definition = $this->registry->get($tableName);
        $primaryKeys = $definition !== null ? $definition->primaryKeys : [];

        return new InsertMutation(
            $tableName,
            $primaryKeys,
            true,
            candidateKeys: $definition?->candidateKeys(),
        );
    }
}
