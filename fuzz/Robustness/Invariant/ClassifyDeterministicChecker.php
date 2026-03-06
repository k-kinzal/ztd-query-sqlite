<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Invariant;

use Throwable;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;

final class ClassifyDeterministicChecker implements InvariantChecker
{
    private SqliteQueryGuard $guard;

    public function __construct(SqliteQueryGuard $guard)
    {
        $this->guard = $guard;
    }

    public function check(string $sql): ?InvariantViolation
    {
        try {
            $result1 = $this->guard->classify($sql);
            $result2 = $this->guard->classify($sql);
        } catch (Throwable $e) {
            return null;
        }

        if ($result1 !== $result2) {
            return new InvariantViolation(
                'INV-L1-02',
                'classify() returned different results for the same SQL',
                $sql,
                [
                    'result1' => $result1 !== null ? $result1->value : 'null',
                    'result2' => $result2 !== null ? $result2->value : 'null',
                ]
            );
        }

        return null;
    }
}
