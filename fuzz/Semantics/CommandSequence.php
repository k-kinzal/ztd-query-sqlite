<?php

declare(strict_types=1);

namespace Fuzz\Semantics;

use SqlFaker\Generation\Choice\BytePlanCompiler;
use SqlFaker\SqliteProvider;

/**
 * Compiles sequence bytes into frozen SQLFaker plans over a bounded SQLite fixture.
 */
final class CommandSequence
{
    /**
     * Keeps the grammar planner outside the per-input reset boundary.
     */
    public function __construct(private readonly SqliteProvider $provider)
    {
    }

    /**
     * Returns up to 32 schema-valid commands; no SQL is assembled by the harness.
     *
     * @return list<string>
     */
    public function compile(string $input): array
    {
        $commands = [];
        $nextId = 3;
        foreach (str_split(substr($input, 0, 128), 4) as $bytes) {
            $operation = ord($bytes[0]) % 6;
            $id = $operation === 1 ? $nextId++ : ord($bytes[1] ?? "\0") % $nextId + 1;
            $value = ord($bytes[2] ?? "\0");
            $name = ["'Alice'", "'O''Brien'", "'\u{2603}'", "''"][ord($bytes[3] ?? "\0") % 4];
            $constraints = StatementPlans::operation($operation, $id, $value, $name);
            $plan = (new BytePlanCompiler())->compile($bytes, $this->provider->planner(), $constraints);
            $commands[] = $this->provider->generate($plan);
        }
        return $commands;
    }
}
