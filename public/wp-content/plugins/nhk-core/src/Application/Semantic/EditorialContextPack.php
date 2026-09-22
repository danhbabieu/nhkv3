<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient editorial read model; it is never persisted as semantic truth. */
final readonly class EditorialContextPack
{
    /** @param list<array<string,mixed>> $selectedClaims @param list<array<string,mixed>> $excludedCandidates @param list<array<string,mixed>> $visualSupport */
    public function __construct(
        public string $status,
        public array $primarySubject,
        public string $topic,
        public array $profile,
        public string $retrievalStatus,
        public array $selectedClaims,
        public array $excludedCandidates,
        public array $visualSupport = [],
        public array $blockers = [],
        public array $diagnostics = [],
        public int $packRevision = 1,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'pack_revision' => $this->packRevision,
            'status' => $this->status,
            'primary_subject' => $this->primarySubject,
            'topic' => $this->topic,
            'profile' => $this->profile,
            'retrieval_status' => $this->retrievalStatus,
            'selected_claims' => $this->selectedClaims,
            'excluded_candidates' => $this->excludedCandidates,
            'visual_support' => $this->visualSupport,
            'blockers' => $this->blockers,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
