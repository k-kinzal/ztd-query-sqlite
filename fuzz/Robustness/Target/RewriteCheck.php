<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Target;

use Error;
use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement;
use ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement;
use ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser;
use ZtdQuery\Rewrite\QueryKind;

/**
 * Checks the public rewrite-plan contract and the removal of physical-table references.
 */
final class RewriteCheck
{
    /**
     * Allows only absent fixture schemas and unsupported SQL; all other failures are findings.
     *
     * @throws Error When a rewrite violates its classification or shadowing contract.
     */
    public static function verify(SqliteQueryGuard $guard, SqliteRewriter $rewriter, string $sql): void
    {
        $kind = $guard->classify($sql);
        if ($kind !== $guard->classify($sql)) {
            throw new Error('Classification changed for identical SQL');
        }
        $passthrough = SqliteReadOnlyDiagnosticStatement::isSafe($sql) || SqliteInMemoryAttachStatement::isSafe($sql);
        if ($passthrough && $kind !== QueryKind::READ) {
            throw new Error('Safe passthrough was not classified as READ');
        }
        try {
            $plan = $rewriter->rewrite($sql);
        } catch (UnsupportedSqlException | UnknownSchemaException $rejection) {
            if ($passthrough) {
                throw new Error('Safe passthrough was rejected', 0, $rejection);
            }
            return;
        }
        if ($plan->kind() !== $kind) {
            throw new Error('Classification and rewrite disagree on the query kind');
        }
        if ($passthrough && $plan->sql() !== $sql) {
            throw new Error('Safe passthrough SQL was changed');
        }
        $writes = $kind === QueryKind::WRITE_SIMULATED || $kind === QueryKind::DDL_SIMULATED;
        if ($writes !== ($plan->mutation() !== null)) {
            throw new Error('Mutation presence does not match the query kind');
        }
        if ($plan->sql() === '') {
            throw new Error('Rewritten SQL is empty');
        }
        $tables = ['users', 'orders', 'order_items', 'products'];
        $relations = new SqliteSelectRelationParser();
        if ($relations->unqualify($sql, $tables) !== $sql && $relations->unqualify($plan->sql(), $tables) !== $plan->sql()) {
            throw new Error('Schema-qualified shadow source survived rewrite');
        }
        $hints = new SqliteIndexHintStripper();
        if ($hints->strip($sql, $tables) !== $sql && $hints->strip($plan->sql(), $tables) !== $plan->sql()) {
            throw new Error('Physical index hint survived shadow-source rewrite');
        }
    }
}
