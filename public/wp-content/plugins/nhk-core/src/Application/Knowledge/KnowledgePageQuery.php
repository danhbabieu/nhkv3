<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim};
use NHK\Core\Shared\Migration\MigrationStatus;

final class KnowledgePageQuery
{
    public function __construct(private KnowledgeRepository $claims, private EvidenceRepository $evidence, private SourceRepository $sources, private ?MigrationStatus $status = null) {}

    public function detail(string $key): ?array
    {
        if (!$this->available()) return null;
        $claim = preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 ? $this->claims->findByCanonicalId($key) : $this->claims->findByStableKey($key);
        if (!$claim || !$claim->active) return null;
        $evidence = array_values(array_filter($this->evidence->listByClaim($claim->canonicalId), function (Evidence $item): bool {
            if (!$item->active) return false;
            $source = $this->sources->findByCanonicalId($item->sourceId);
            return $source !== null && $source->active;
        }));
        return ['id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'text' => $claim->claimText, 'type' => $claim->claimType, 'provenance' => $claim->provenance, 'revision' => $claim->revision, 'evidence' => array_map($this->evidence(...), $evidence)];
    }

    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function archive(int $page = 1, int $perPage = 24): array
    {
        if (!$this->available()) return ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'items' => []];
        $items = array_map(fn (KnowledgeClaim $claim): array => ['id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'text' => $claim->claimText, 'type' => $claim->claimType], array_values(array_filter($this->claims->list(), static fn (KnowledgeClaim $claim): bool => $claim->active)));
        $page = max(1, $page); $perPage = min(100, max(1, $perPage));
        return ['page' => $page, 'per_page' => $perPage, 'total' => count($items), 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    private function available(): bool { return !$this->status || $this->status->knowledgeStorageReady(); }
    private function evidence(Evidence $item): array { return ['id' => $item->canonicalId, 'claim_id' => $item->claimId, 'source_id' => $item->sourceId, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator, 'metadata' => $item->metadata, 'revision' => $item->revision]; }
}
