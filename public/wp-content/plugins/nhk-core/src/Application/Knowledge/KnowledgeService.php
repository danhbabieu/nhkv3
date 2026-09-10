<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Dictionary\DictionaryObservationRegistry;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{CollectorFacetRegistry, Evidence, KnowledgeClaim, KnowledgeException, Source};
use NHK\Core\Shared\Uuid\UuidCodec;

final class KnowledgeService
{
    public function __construct(private KnowledgeRepository $claims, private SourceRepository $sources, private EvidenceRepository $evidence, private $dictionaryObserver = null) {}

    public function createClaim(string $stableKey, string $text, string $type = 'fact', array $provenance = []): KnowledgeClaim
    {
        $existing = $this->claims->findByStableKey($stableKey);
        if ($existing) { if ($existing->claimText === $text && $existing->claimType === $type && $existing->provenance === $provenance) return $existing; throw new KnowledgeException('Knowledge claim stable key already exists.'); }
        $claim = $this->claims->create(new KnowledgeClaim(UuidCodec::newV7(), $stableKey, $text, $type, $provenance));
        $this->observe($claim);
        return $claim;
    }

    public function createSource(string $stableKey, string $title, string $type = 'website', ?string $locator = null, array $metadata = []): Source
    {
        $existing = $this->sources->findByStableKey($stableKey);
        if ($existing) { if ($existing->title === $title && $existing->sourceType === $type && $existing->locator === $locator && $existing->metadata === $metadata) return $existing; throw new KnowledgeException('Source stable key already exists.'); }
        return $this->sources->create(new Source(UuidCodec::newV7(), $stableKey, $title, $type, $locator, $metadata));
    }

    public function updateClaim(string $id, string $text, string $type, array $provenance, int $revision): KnowledgeClaim
    {
        $current = $this->claims->findByCanonicalId($id);
        if (!$current) throw new KnowledgeException('Knowledge claim not found.');
        $claim = $this->claims->update(new KnowledgeClaim($current->canonicalId, $current->stableKey, $text, $type, $provenance, $current->active, $current->revision), $revision);
        $this->observe($claim);
        return $claim;
    }

    /** Update only the governed Collector facet metadata on an existing claim. */
    public function updateCollectorFacet(string $id, string $facet, int $revision, array $binding): KnowledgeClaim
    {
        if (!CollectorFacetRegistry::isValid($facet)) throw new KnowledgeException('COLLECTOR_FACET_INVALID');
        $current = $this->claims->findByCanonicalId($id);
        if (!$current) throw new KnowledgeException('COLLECTOR_FACET_TARGET_NOT_FOUND');
        if ($current->revision !== $revision) throw new KnowledgeException('COLLECTOR_FACET_STALE_REVISION');
        if ((string) ($binding['knowledge_uuid'] ?? $id) !== $current->canonicalId
            || (string) ($binding['stable_key'] ?? '') !== $current->stableKey
            || (string) ($binding['claim_text_sha256'] ?? '') !== hash('sha256', $current->claimText)
            || (string) ($binding['claim_type'] ?? '') !== $current->claimType
            || (string) ($binding['provenance_sha256'] ?? '') !== self::fingerprint($current->provenance)) {
            throw new KnowledgeException('COLLECTOR_FACET_BINDING_MISMATCH');
        }
        $metadata = is_array($current->provenance['metadata'] ?? null) ? $current->provenance['metadata'] : [];
        $scope = (string) ($metadata['scope'] ?? '');
        if ((string) ($binding['scope'] ?? $scope) !== $scope || !CollectorFacetRegistry::isValidForScope($facet, $scope)) {
            throw new KnowledgeException('COLLECTOR_FACET_SCOPE_INVALID');
        }
        if (CollectorFacetRegistry::resolve($metadata) === $facet) return $current;
        $provenance = $current->provenance;
        $provenance['metadata'] = $metadata + [CollectorFacetRegistry::METADATA_KEY => $facet];
        $provenance['metadata'][CollectorFacetRegistry::METADATA_KEY] = $facet;
        return $this->claims->update(
            new KnowledgeClaim($current->canonicalId, $current->stableKey, $current->claimText, $current->claimType, $provenance, $current->active, $current->revision),
            $revision,
        );
    }

    public function retireClaim(string $id, int $revision): KnowledgeClaim { return $this->changeClaimState($id, $revision, false); }
    public function reactivateClaim(string $id, int $revision): KnowledgeClaim { return $this->changeClaimState($id, $revision, true); }

    public function updateSource(string $id, string $title, string $type, ?string $locator, array $metadata, int $revision): Source
    {
        $current = $this->sources->findByCanonicalId($id);
        if (!$current) throw new KnowledgeException('Source not found.');
        return $this->sources->update(new Source($current->canonicalId, $current->stableKey, $title, $type, $locator, $metadata, $current->active, $current->revision), $revision);
    }

    public function retireSource(string $id, int $revision): Source { return $this->changeSourceState($id, $revision, false); }
    public function reactivateSource(string $id, int $revision): Source { return $this->changeSourceState($id, $revision, true); }

    public function updateEvidence(string $id, string $relation, string $excerpt, ?string $locator, array $metadata, int $revision): Evidence
    {
        $current = $this->evidence->findByCanonicalId($id);
        if (!$current) throw new KnowledgeException('Evidence not found.');
        return $this->evidence->update(new Evidence($current->canonicalId, $current->claimId, $current->sourceId, $relation, $excerpt, $locator, $current->active, $current->revision, $metadata), $revision);
    }

    public function retireEvidence(string $id, int $revision): Evidence { return $this->changeEvidenceState($id, $revision, false); }
    public function reactivateEvidence(string $id, int $revision): Evidence { return $this->changeEvidenceState($id, $revision, true); }

    public function cite(string $claimId, string $sourceId, string $excerpt, string $relation = 'supports', ?string $locator = null, array $metadata = []): Evidence
    {
        return $this->citeWithId(UuidCodec::newV7(), $claimId, $sourceId, $excerpt, $relation, $locator, $metadata);
    }

    public function citeWithId(string $evidenceId, string $claimId, string $sourceId, string $excerpt, string $relation = 'supports', ?string $locator = null, array $metadata = []): Evidence
    {
        if (!UuidCodec::isValid($evidenceId)) throw new KnowledgeException('Evidence identity is invalid.');
        if (!$this->claims->findByCanonicalId($claimId) || !$this->sources->findByCanonicalId($sourceId)) throw new KnowledgeException('Evidence endpoint does not exist.');
        return $this->evidence->create(new Evidence($evidenceId, $claimId, $sourceId, $relation, $excerpt, $locator, true, 1, $metadata));
    }

    /** @return list<Evidence> */
    public function evidenceForClaim(string $claimId, bool $includeRetired = false): array { return $this->evidence->listByClaim($claimId, $includeRetired); }

    private function changeClaimState(string $id, int $revision, bool $active): KnowledgeClaim
    {
        $current = $this->claims->findByCanonicalId($id);
        if (!$current) throw new KnowledgeException('Knowledge claim not found.');
        if ($current->active === $active) return $current;
        return $this->claims->update(new KnowledgeClaim($current->canonicalId, $current->stableKey, $current->claimText, $current->claimType, $current->provenance, $active, $current->revision), $revision);
    }

    private function changeSourceState(string $id, int $revision, bool $active): Source
    {
        $current = $this->sources->findByCanonicalId($id);
        if (!$current) throw new KnowledgeException('Source not found.');
        if ($current->active === $active) return $current;
        return $this->sources->update(new Source($current->canonicalId, $current->stableKey, $current->title, $current->sourceType, $current->locator, $current->metadata, $active, $current->revision), $revision);
    }

    private function changeEvidenceState(string $id, int $revision, bool $active): Evidence
    {
        $current = $this->evidence->findByCanonicalId($id);
        if (!$current) throw new KnowledgeException('Evidence not found.');
        if ($current->active === $active) return $current;
        return $this->evidence->update(new Evidence($current->canonicalId, $current->claimId, $current->sourceId, $current->relation, $current->excerpt, $current->locator, $active, $current->revision, $current->metadata), $revision);
    }

    private function observe(KnowledgeClaim $claim): void
    {
        $context = ['claim_type' => $claim->claimType, 'provenance' => $claim->provenance];
        if (is_callable($this->dictionaryObserver)) {
            try { ($this->dictionaryObserver)('KNOWLEDGE', $claim->canonicalId, $claim->claimText, $context); } catch (\Throwable) {}
            return;
        }
        DictionaryObservationRegistry::observe('KNOWLEDGE', $claim->canonicalId, $claim->claimText, $context);
    }

    private static function fingerprint(array $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            if (array_keys($item) !== range(0, count($item) - 1)) ksort($item);
            return $item;
        };
        return hash('sha256', (string) json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
