<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Read-only result of deciding whether a shadow result may enter Governance. */
final readonly class ClockTypeMembershipPlanningResult
{
    public const QUALIFIED = 'QUALIFIED';
    public const ALREADY_CANONICAL = 'ALREADY_CANONICAL';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const BLOCKED = 'BLOCKED';
    public const NONE = 'NONE';
    public const UNAVAILABLE = 'UNAVAILABLE';

    public function __construct(
        public string $status,
        public ?ClockTypeMembershipCandidate $candidate = null,
        public array $blockers = [],
        public array $diagnostics = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'candidate' => $this->candidate?->toArray(),
            'blockers' => array_values($this->blockers),
            'diagnostics' => array_values($this->diagnostics),
            'writes' => [],
        ];
    }
}
