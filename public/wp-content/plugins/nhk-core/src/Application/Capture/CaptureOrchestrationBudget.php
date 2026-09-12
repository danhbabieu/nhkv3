<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

final class CaptureOrchestrationBudgetExceeded extends \RuntimeException
{
    public function __construct(public readonly string $phase, public readonly int $elapsedMs, public readonly int $budgetMs)
    {
        parent::__construct('CAPTURE_ORCHESTRATION_BUDGET_EXCEEDED');
    }
}

/** Monotonic application budget scoped to one invocation. */
final class CaptureOrchestrationBudget
{
    private float $startedAt = 0.0;

    /** @param callable():float|null $clock Clock returns monotonic milliseconds. */
    public function __construct(private readonly int $budgetMs = 5000, private $clock = null)
    {
        if ($budgetMs < 1) throw new \InvalidArgumentException('Capture orchestration budget must be positive.');
    }
    public function begin(): void { $this->startedAt = $this->now(); }
    public function elapsedMs(): int { return $this->startedAt <= 0.0 ? 0 : max(0, (int) floor($this->now() - $this->startedAt)); }
    public function remainingMs(): int { return max(0, $this->budgetMs - $this->elapsedMs()); }
    public function check(string $phase): void
    {
        if ($this->startedAt <= 0.0) $this->begin();
        if ($this->elapsedMs() >= $this->budgetMs) throw new CaptureOrchestrationBudgetExceeded($phase, $this->elapsedMs(), $this->budgetMs);
    }
    public function budgetMs(): int { return $this->budgetMs; }
    private function now(): float
    {
        if (is_callable($this->clock)) return (float) ($this->clock)();
        return function_exists('hrtime') ? ((float) hrtime(true) / 1000000.0) : microtime(true) * 1000.0;
    }
}
