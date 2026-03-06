<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Invariant;

use Throwable;
use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;
use ZtdQuery\Rewrite\SqlRewriter;

final class ClassifyRewriteAgreementChecker implements InvariantChecker
{
    private SqliteQueryGuard $guard;
    private SqlRewriter $rewriter;

    public function __construct(SqliteQueryGuard $guard, SqlRewriter $rewriter)
    {
        $this->guard = $guard;
        $this->rewriter = $rewriter;
    }

    public function check(string $sql): ?InvariantViolation
    {
        try {
            $classifyResult = $this->guard->classify($sql);
        } catch (Throwable) {
            return null;
        }

        if ($classifyResult === null) {
            try {
                $this->rewriter->rewrite($sql);
                return null;
            } catch (UnsupportedSqlException) {
                return null;
            } catch (Throwable) {
                return null;
            }
        }

        try {
            $plan = $this->rewriter->rewrite($sql);
        } catch (UnknownSchemaException) {
            return null;
        } catch (UnsupportedSqlException) {
            return null;
        } catch (Throwable) {
            return null;
        }

        if ($plan->kind() !== $classifyResult) {
            return new InvariantViolation(
                'INV-L2-05',
                'classify() and rewrite() disagree on QueryKind',
                $sql,
                [
                    'classify_result' => $classifyResult->value,
                    'rewrite_kind' => $plan->kind()->value,
                ]
            );
        }

        return null;
    }
}
