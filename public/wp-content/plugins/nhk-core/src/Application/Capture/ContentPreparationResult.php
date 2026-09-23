<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Capture\SubjectResolutionPacket;

/**
 * Transient preparation outcome carried by the durable Capture boundary.
 * It is not a semantic owner, persisted domain status or storage record.
 */
final readonly class ContentPreparationResult
{
    /**
     * @param array<int|string,mixed> $candidates
     * @param array<string,mixed> $gaps
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $enrichment
     * @param array<string,mixed> $diagnostics
     * @param list<string> $blockers
     * @param list<string> $reviewReasons
     * @param list<string> $warnings
     * @param list<array<string,mixed>> $decisionTrace
     * @param list<array<string,mixed>> $constraintFindings
     * @param list<array<string,mixed>> $dependencyFindings
     */
    public function __construct(
        public string $status,
        public string $preparationFingerprint,
        public ?SubjectResolutionPacket $subjectResolutionPacket = null,
        public array $candidates = [],
        public array $gaps = [],
        public array $plan = [],
        public array $enrichment = [],
        public array $diagnostics = [],
        public array $blockers = [],
        public array $reviewReasons = [],
        public array $warnings = [],
        public array $decisionTrace = [],
        public array $constraintFindings = [],
        public string $qualityDecision = 'READY',
        public int $repairRounds = 0,
        public array $dependencyFindings = [],
    ) {
        if (!in_array($status, ['PREPARED', 'REVIEW_REQUIRED', 'BLOCKED'], true)) {
            throw new \InvalidArgumentException('Content preparation status is invalid.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $preparationFingerprint)) {
            throw new \InvalidArgumentException('Content preparation fingerprint is invalid.');
        }
        if ($status === 'PREPARED' && ($subjectResolutionPacket === null || $subjectResolutionPacket->status !== 'resolved')) {
            throw new \InvalidArgumentException('Prepared content requires a resolved subject packet.');
        }
        if (!in_array($this->qualityDecision, ['READY', 'REVIEW_REQUIRED', 'HARD_BLOCK'], true)) throw new \InvalidArgumentException('Content quality decision is invalid.');
        if ($this->repairRounds < 0 || $this->repairRounds > 3) throw new \InvalidArgumentException('Content repair rounds are invalid.');
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): ?self
    {
        try {
            $status = strtoupper(trim((string) ($value['status'] ?? '')));
            $packet = is_array($value['subject_resolution_packet'] ?? null)
                ? SubjectResolutionPacket::fromArray($value['subject_resolution_packet'])
                : null;
            return new self(
                $status,
                trim((string) ($value['preparation_fingerprint'] ?? '')),
                $packet,
                is_array($value['candidates'] ?? null) ? $value['candidates'] : [],
                is_array($value['gaps'] ?? null) ? $value['gaps'] : [],
                is_array($value['plan'] ?? null) ? $value['plan'] : [],
                is_array($value['enrichment'] ?? null) ? $value['enrichment'] : [],
                is_array($value['diagnostics'] ?? null) ? $value['diagnostics'] : [],
                array_values(array_map('strval', (array) ($value['blockers'] ?? []))),
                array_values(array_map('strval', (array) ($value['review_reasons'] ?? []))),
                array_values(array_map('strval', (array) ($value['warnings'] ?? []))),
                is_array($value['decision_trace'] ?? null) ? $value['decision_trace'] : [],
                is_array($value['constraint_findings'] ?? null) ? $value['constraint_findings'] : [],
                trim((string) ($value['quality_decision'] ?? 'READY')),
                max(0, min(3, (int) ($value['repair_rounds'] ?? 0))),
                is_array($value['dependency_findings'] ?? null) ? $value['dependency_findings'] : [],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'preparation_fingerprint' => $this->preparationFingerprint,
            'subject_resolution_packet' => $this->subjectResolutionPacket?->toArray(),
            'candidates' => $this->candidates,
            'gaps' => $this->gaps,
            'plan' => $this->plan,
            'enrichment' => $this->enrichment,
            'diagnostics' => $this->diagnostics,
            'blockers' => array_values(array_map('strval', $this->blockers)),
            'review_reasons' => array_values(array_map('strval', $this->reviewReasons)),
            'warnings' => array_values(array_map('strval', $this->warnings)),
            'decision_trace' => $this->decisionTrace,
            'constraint_findings' => $this->constraintFindings,
            'quality_decision' => $this->qualityDecision,
            'repair_rounds' => $this->repairRounds,
            'dependency_findings' => $this->dependencyFindings,
        ];
    }
}
