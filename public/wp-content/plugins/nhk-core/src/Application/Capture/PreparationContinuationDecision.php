<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Workflow permission; not semantic, owner or publication truth. */
final readonly class PreparationContinuationDecision
{
    /** @param list<string> $blockingFindings @param list<string> $deferredFindings @param array<string,mixed> $trace */
    public function __construct(
        public bool $mayContinue,
        public array $blockingFindings = [],
        public array $deferredFindings = [],
        public string $reason = '',
        public array $trace = [],
    ) {
        if (trim($this->reason) === '') throw new \InvalidArgumentException('Preparation continuation reason is required.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'may_continue' => $this->mayContinue,
            'blocking_findings' => array_values($this->blockingFindings),
            'deferred_findings' => array_values($this->deferredFindings),
            'reason' => $this->reason,
            'trace' => $this->trace,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): ?self
    {
        try {
            return new self(
                ($value['may_continue'] ?? false) === true,
                array_values(array_map('strval', (array) ($value['blocking_findings'] ?? []))),
                array_values(array_map('strval', (array) ($value['deferred_findings'] ?? []))),
                trim((string) ($value['reason'] ?? '')),
                is_array($value['trace'] ?? null) ? $value['trace'] : [],
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
