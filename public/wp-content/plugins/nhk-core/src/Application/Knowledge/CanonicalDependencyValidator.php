<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{DependencyValidationException, Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;

final class CanonicalDependencyValidator
{
    public function __construct(private KnowledgeRepository $claims, private SourceRepository $sources, private EvidenceRepository $evidence) {}

    public function claim(string $id, string $field = 'claim_id'): KnowledgeClaim
    {
        $claim = UuidCodec::isValid($id) ? $this->claims->findByCanonicalId($id) : null;
        if (!$claim) throw new DependencyValidationException('CANONICAL_CLAIM_REQUIRED', $field, $id, 'Claim must resolve by canonical UUID.');
        if (!$claim->active) throw new DependencyValidationException('CLAIM_NOT_ACTIVE', $field, $id, 'Claim dependency is not active.');
        return $claim;
    }

    public function source(string $id, string $field = 'source_id'): Source
    {
        $source = UuidCodec::isValid($id) ? $this->sources->findByCanonicalId($id) : null;
        if (!$source) throw new DependencyValidationException('CANONICAL_SOURCE_REQUIRED', $field, $id, 'Source must resolve by canonical UUID.');
        if (!$source->active) throw new DependencyValidationException('SOURCE_NOT_ACTIVE', $field, $id, 'Source dependency is not active.');
        return $source;
    }

    public function evidence(string $id, string $field = 'evidence_id'): Evidence
    {
        $evidence = UuidCodec::isValid($id) ? $this->evidence->findByCanonicalId($id) : null;
        if (!$evidence) throw new DependencyValidationException('CANONICAL_EVIDENCE_REQUIRED', $field, $id, 'Evidence must resolve by canonical UUID.');
        if (!$evidence->active) throw new DependencyValidationException('EVIDENCE_NOT_ACTIVE', $field, $id, 'Evidence dependency is not active.');
        $this->claim($evidence->claimId, 'evidence.claim_id');
        $this->source($evidence->sourceId, 'evidence.source_id');
        return $evidence;
    }
}
