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
        ];
    }
}
