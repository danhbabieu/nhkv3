<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgeException, Source};
use NHK\Core\Shared\Uuid\UuidCodec;

final class KnowledgeService
{
    public function __construct(private KnowledgeRepository $claims, private SourceRepository $sources, private EvidenceRepository $evidence) {}

    public function createClaim(string $stableKey, string $text, string $type = 'fact', array $provenance = []): KnowledgeClaim
    {
        $existing = $this->claims->findByStableKey($stableKey);
        if ($existing) { if ($existing->claimText === $text && $existing->claimType === $type && $existing->provenance === $provenance) return $existing; throw new KnowledgeException('Knowledge claim stable key already exists.'); }
        return $this->claims->create(new KnowledgeClaim(UuidCodec::newV7(), $stableKey, $text, $type, $provenance));
    }

    public function createSource(string $stableKey, string $title, string $type = 'website', ?string $locator = null, array $metadata = []): Source
    {
        $existing = $this->sources->findByStableKey($stableKey);
        if ($existing) { if ($existing->title === $title && $existing->sourceType === $type && $existing->locator === $locator && $existing->metadata === $metadata) return $existing; throw new KnowledgeException('Source stable key already exists.'); }
        return $this->sources->create(new Source(UuidCodec::newV7(), $stableKey, $title, $type, $locator, $metadata));
    }

    public function cite(string $claimId, string $sourceId, string $excerpt, string $relation = 'supports', ?string $locator = null): Evidence
    {
        if (!$this->claims->findByCanonicalId($claimId) || !$this->sources->findByCanonicalId($sourceId)) throw new KnowledgeException('Evidence endpoint does not exist.');
        return $this->evidence->create(new Evidence(UuidCodec::newV7(), $claimId, $sourceId, $relation, $excerpt, $locator));
    }

    /** @return list<Evidence> */
    public function evidenceForClaim(string $claimId, bool $includeRetired = false): array { return $this->evidence->listByClaim($claimId, $includeRetired); }
}
