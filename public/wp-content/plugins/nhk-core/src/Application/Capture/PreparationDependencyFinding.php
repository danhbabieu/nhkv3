<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/** Local dependency readiness; not a semantic owner or persisted truth. */
final readonly class PreparationDependencyFinding
{
    /** @param array<string,mixed> $trace */
    public function __construct(
        public string $code,
        public PreparationDependencyClass $dependencyClass,
        public string $readiness,
        public ?string $escalation,
        public string $reason,
        public array $trace = [],
    ) {
        if (trim($this->code) === '' || trim($this->reason) === '') throw new \InvalidArgumentException('Preparation dependency finding is incomplete.');
        if (!in_array($this->readiness, ['READY', 'INCOMPLETE', 'BLOCKED', 'UNAVAILABLE', 'NOT_APPLICABLE'], true)) throw new \InvalidArgumentException('Preparation dependency readiness is invalid.');
        if ($this->escalation !== null && !in_array($this->escalation, ['REVIEW_REQUIRED', 'BLOCKED'], true)) throw new \InvalidArgumentException('Preparation dependency escalation is invalid.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'dependency_class' => $this->dependencyClass->value,
            'readiness' => $this->readiness,
            'escalation' => $this->escalation,
            'escalates' => $this->escalation !== null,
            'reason' => $this->reason,
            'trace' => $this->trace,
        ];
    }
}
