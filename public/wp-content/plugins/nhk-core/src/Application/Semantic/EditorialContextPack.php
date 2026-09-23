<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient editorial read model; it is never persisted as semantic truth. */
final readonly class EditorialContextPack
{
    /** @var list<array<string,mixed>> */
    public array $grounding;
    /** @var list<array<string,mixed>> */
    public array $readerFacts;
    /** @var list<array<string,mixed>> */
    public array $supportingContext;
    /** @var list<array<string,mixed>> */
    public array $specimenContext;
    /** @var list<array<string,mixed>> */
    public array $controlProvenance;

    /** @param list<array<string,mixed>> $selectedClaims @param list<array<string,mixed>> $excludedCandidates @param list<array<string,mixed>> $visualSupport */
    public function __construct(
        public string $status,
        public array $primarySubject,
        public string $topic,
        public array $profile,
        public string $retrievalStatus,
        public array $selectedClaims,
        public array $excludedCandidates,
        public array $inputContext = [],
        public array $visualSupport = [],
        public array $blockers = [],
        public array $diagnostics = [],
        public int $packRevision = 1,
        /** @var list<array<string,mixed>> */
        array $grounding = [],
        /** @var list<array<string,mixed>> */
        array $readerFacts = [],
        /** @var list<array<string,mixed>> */
        array $supportingContext = [],
        /** @var list<array<string,mixed>> */
        array $specimenContext = [],
        /** @var list<array<string,mixed>> */
        array $controlProvenance = [],
    ) {
        $groundingBucket = $grounding;
        $readerFactsBucket = $readerFacts;
        $supportingContextBucket = $supportingContext;
        $specimenContextBucket = $specimenContext;
        $controlProvenanceBucket = $controlProvenance;
        if ($groundingBucket === [] && $readerFactsBucket === [] && $supportingContextBucket === [] && $specimenContextBucket === [] && $controlProvenanceBucket === []) {
            foreach ($this->selectedClaims as $claim) {
                $role = strtoupper(trim((string) ($claim['semantic_role'] ?? '')));
                if ($role === 'GROUNDING' || $role === 'PROVENANCE_ONLY') $groundingBucket[] = $claim;
                elseif ($role === 'SUPPORTING_CONTEXT' || strtoupper((string) ($claim['editorial_role'] ?? '')) === 'EXPLANATION') $supportingContextBucket[] = $claim;
                elseif ($role === 'SPECIMEN_CONTEXT') $specimenContextBucket[] = $claim;
                elseif ($role === 'CONTROL_ONLY') $controlProvenanceBucket[] = $claim;
                else $readerFactsBucket[] = $claim;
            }
        }
        $this->grounding = array_values($groundingBucket);
        $this->readerFacts = array_values($readerFactsBucket);
        $this->supportingContext = array_values($supportingContextBucket);
        $this->specimenContext = array_values($specimenContextBucket);
        $this->controlProvenance = array_values($controlProvenanceBucket);
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
            'input_context' => $this->inputContext,
            'visual_support' => $this->visualSupport,
            'blockers' => $this->blockers,
            'diagnostics' => $this->diagnostics,
            'grounding' => $this->grounding,
            'reader_facts' => $this->readerFacts,
            'supporting_context' => $this->supportingContext,
            'specimen_context' => $this->specimenContext,
            'control_provenance' => $this->controlProvenance,
        ];
    }
}
