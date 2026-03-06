<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Invariant;

use Throwable;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;

final class ClassifyNeverThrowsChecker implements InvariantChecker
{
    private SqliteQueryGuard $guard;

    public function __construct(SqliteQueryGuard $guard)
    {
        $this->guard = $guard;
    }

    public function check(string $sql): ?InvariantViolation
    {
        try {
            $this->guard->classify($sql);
            return null;
        } catch (Throwable $e) {
            return new InvariantViolation(
                'INV-L1-01',
                'classify() threw an exception',
                $sql,
                [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                ]
            );
        }
    }
}
