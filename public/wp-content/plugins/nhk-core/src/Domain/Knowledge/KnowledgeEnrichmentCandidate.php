<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class KnowledgeEnrichmentCandidate
{
    public const CLASSIFICATIONS = ['same_claim', 'add_evidence', 'new_claim', 'qualify', 'contradict', 'scope_promotion_review', 'no_enrichment', 'ambiguous', 'unsupported'];

    public function __construct(
        public string $classification,
        public string $subjectId,
        public KnowledgeFacetProfile $profile,
        public string $observation,
        public array $provenance = [],
        public bool $isGenerated = false,
        public bool $isEvidence = false,
    ) {
        if (!in_array($classification, self::CLASSIFICATIONS, true) || !UuidCodec::isValid($subjectId) || trim($observation) === '') {
            throw new \InvalidArgumentException('Invalid Knowledge enrichment candidate.');
        }
        if ($isGenerated && $isEvidence) {
            throw new \InvalidArgumentException('Generated copy cannot be Evidence.');
        }
    }
}
