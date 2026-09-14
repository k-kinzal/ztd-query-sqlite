<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Input;

/**
 * Maps structural bytes to frozen SQLite grammar plans, with a separate raw-SQL branch.
 */
final class SqlInput
{
    /**
     * Pins the SQLite grammar and keeps its planning state outside the fuzz loop.
     */
    public function __construct(private \SqlFaker\SqliteProvider $provider)
    {
    }

    /**
     * Returns replayable SQL bounded by the engine length and plan expansion limits.
     */
    public function sql(string $input): string
    {
        if ((ord($input[0] ?? "\0") & 1) === 1) {
            return substr($input, 1);
        }
        $constraints = \SqlFaker\Generation\Plan\GenerationPlan::fromRule('cmd')
            ->requiringNonEmpty()->withExpansionBudget(256)->withMaxDepth(8);
        $plan = (new \SqlFaker\Generation\Choice\BytePlanCompiler())->compile(
            substr($input, 1),
            $this->provider->planner(),
            $constraints,
        );

        return $this->provider->generate($plan);
    }
}
