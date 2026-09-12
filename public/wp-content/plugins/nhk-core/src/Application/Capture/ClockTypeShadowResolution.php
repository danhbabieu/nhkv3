<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/**
 * Read-only Capture shadow packet. The selected candidate is only a shadow
 * selection for diagnostics and review; it is never semantic truth.
 */
final readonly class ClockTypeShadowResolution
{
    public const RESOLVED_CANONICAL = 'RESOLVED_CANONICAL';
    public const RESOLVED_EXPLICIT = 'RESOLVED_EXPLICIT';
    public const REVIEW_CANDIDATE = 'REVIEW_CANDIDATE';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const NONE = 'NONE';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const DATA_COMPATIBILITY_GAP = 'DATA_COMPATIBILITY_GAP';

    /**
     * @param list<ClockTypeShadowCandidate> $candidates
     * @param list<string> $basis
     * @param list<string> $ambiguities
     * @param list<string> $diagnostics
     * @param array<string,mixed> $canonicalSubject
     */
    public function __construct(
        public string $status,
        public array $candidates,
        public ?ClockTypeShadowCandidate $selectedCandidate,
        public array $basis,
        public array $ambiguities,
        public array $diagnostics,
        public array $canonicalSubject,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'candidates' => array_map(static fn (ClockTypeShadowCandidate $candidate): array => $candidate->toArray(), $this->candidates),
            'selected_candidate' => $this->selectedCandidate?->toArray(),
            'basis' => $this->basis,
            'ambiguities' => $this->ambiguities,
            'diagnostics' => $this->diagnostics,
            'canonical_subject' => $this->canonicalSubject,
            'shadow_only' => true,
            'writes' => [],
        ];
    }
}
