<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Invariant;

final class InvariantViolation
{
    private string $id;
    private string $description;
    private string $sql;
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $id, string $description, string $sql, array $context = [])
    {
        $this->id = $id;
        $this->description = $description;
        $this->sql = $sql;
        $this->context = $context;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function __toString(): string
    {
        $msg = sprintf("[%s] %s\nSQL: %s", $this->id, $this->description, $this->sql);
        if ($this->context !== []) {
            $msg .= "\nContext: " . json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        return $msg;
    }
}
