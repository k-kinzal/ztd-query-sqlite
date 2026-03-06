<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Invariant;

interface InvariantChecker
{
    public function check(string $sql): ?InvariantViolation;
}
