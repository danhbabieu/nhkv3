<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient reader proposition backed by one or more canonical Claims. */
final readonly class KnowledgeUnit
{
    /** @param array<string,mixed> $claim @param list<array<string,mixed>> $supportingClaims @param list<string> $coverageAspects */
    public function __construct(
        private array $claim,
        private array $supportingClaims,
        private string $semanticFingerprint,
        private array $coverageAspects,
        private array $evidenceRefs,
        private array $provenanceTrace,
        private float $readerUtility,
        private bool $publiclyComposable,
    ) {
    }

    /** @return array<string,mixed> */
    public function claim(): array { return $this->claim; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'unit_id' => $this->semanticFingerprint,
            'claim' => $this->claim,
            'supporting_claims' => $this->supportingClaims,
            'semantic_fingerprint' => $this->semanticFingerprint,
            'coverage_aspects' => $this->coverageAspects,
            'evidence_refs' => $this->evidenceRefs,
            'provenance_trace' => $this->provenanceTrace,
            'reader_utility' => $this->readerUtility,
            'publicly_composable' => $this->publiclyComposable,
        ];
    }
}
