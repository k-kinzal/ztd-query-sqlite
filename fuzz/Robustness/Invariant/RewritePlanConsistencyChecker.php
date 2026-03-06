<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Invariant;

use Throwable;
use ZtdQuery\Rewrite\QueryKind;
use ZtdQuery\Rewrite\RewritePlan;
use ZtdQuery\Rewrite\SqlRewriter;

final class RewritePlanConsistencyChecker implements InvariantChecker
{
    private SqlRewriter $rewriter;

    public function __construct(SqlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    public function check(string $sql): ?InvariantViolation
    {
        try {
            $plan = $this->rewriter->rewrite($sql);
        } catch (Throwable) {
            return null;
        }

        return $this->checkPlan($plan, $sql);
    }

    public function checkPlan(RewritePlan $plan, string $sql): ?InvariantViolation
    {
        $kind = $plan->kind();
        $mutation = $plan->mutation();

        if (($kind === QueryKind::WRITE_SIMULATED || $kind === QueryKind::DDL_SIMULATED) && $mutation === null) {
            return new InvariantViolation(
                'INV-L2-02',
                sprintf('%s plan has null mutation', $kind->value),
                $sql,
                ['kind' => $kind->value]
            );
        }

        if (($kind === QueryKind::READ || $kind === QueryKind::SKIPPED) && $mutation !== null) {
            return new InvariantViolation(
                'INV-L2-03',
                sprintf('%s plan has non-null mutation', $kind->value),
                $sql,
                ['kind' => $kind->value, 'mutation_class' => get_class($mutation)]
            );
        }

        if ($plan->sql() === '') {
            return new InvariantViolation(
                'INV-L2-04',
                'Rewritten SQL is empty',
                $sql,
                ['kind' => $kind->value]
            );
        }

        return null;
    }
}
