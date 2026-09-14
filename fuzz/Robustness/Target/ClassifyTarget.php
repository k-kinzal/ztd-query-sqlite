<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Target;

use Error;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;

/**
 * Checks that arbitrary SQL can be classified without exceptions or state-dependent results.
 */
final class ClassifyTarget
{
    /**
     * Runs classification twice on the same input and reports every unexpected failure.
     * @throws Error
     */
    public function __invoke(string $sql): void
    {
        $guard = new SqliteQueryGuard(new SqliteParser());
        if ($guard->classify($sql) !== $guard->classify($sql)) {
            throw new Error('Classification changed for identical SQL');
        }
    }
}
