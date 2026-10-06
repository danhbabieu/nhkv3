<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Application\Presentation\LatestFirstOrder;

final class KnowledgePageQuery
{
    public function __construct(private KnowledgeRepository $claims, private EvidenceRepository $evidence, private SourceRepository $sources, private ?MigrationStatus $status = null, private ?PublicResearchSourceDisplayPolicy $sourceDisplayPolicy = null) { $this->sourceDisplayPolicy ??= new PublicResearchSourceDisplayPolicy(); }

    public function detail(string $key): ?array
    {
        if (!$this->available()) return null;
        if (preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 && !UuidCodec::isValid($key)) return null;
        $claim = preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 ? $this->claims->findByCanonicalId($key) : $this->claims->findByStableKey($key);
        if (!$claim || !$claim->active || !$claim->isPublic()) return null;
        $policyBlocked = false;
        $evidence = [];
        foreach ($this->evidence->listByClaim($claim->canonicalId) as $item) {
            if (!$item instanceof Evidence || !$item->active || !$item->isPublic()) continue;
            $source = $this->sources->findByCanonicalId($item->sourceId);
            if ($source === null || !$source->active || !$source->isPublic()) continue;
            if (!$this->sourceDisplayPolicy->allows($source)) { $policyBlocked = true; continue; }
            $evidence[] = $item;
        }
        if ($evidence === [] && $policyBlocked) return null;
        return ['text' => $claim->claimText, 'type' => $claim->claimType, 'evidence' => array_map(function (Evidence $item): array { return $this->evidence($item, $this->sources->findByCanonicalId($item->sourceId)); }, $evidence)];
    }

    /** @return array{page:int,per_page:int,total:int,items:list<array<string,mixed>>} */
    public function archive(int $page = 1, int $perPage = 24): array
    {
        if (!$this->available()) return ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'items' => []];
        $claims = LatestFirstOrder::sort(array_values(array_filter($this->claims->list(), fn (KnowledgeClaim $claim): bool => $claim->active && $claim->isPublic() && !$this->hasOnlyPolicyBlockedEvidence($claim))), static fn (KnowledgeClaim $claim): ?string => null, static fn (KnowledgeClaim $claim): ?string => $claim->createdAt, static fn (KnowledgeClaim $claim): string => $claim->canonicalId);
        $items = array_map(fn (KnowledgeClaim $claim): array => ['text' => $claim->claimText, 'type' => $claim->claimType], $claims);
        $page = max(1, $page); $perPage = min(100, max(1, $perPage));
        return ['page' => $page, 'per_page' => $perPage, 'total' => count($items), 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    private function available(): bool { return !$this->status || $this->status->knowledgeStorageReady(); }
    private function hasOnlyPolicyBlockedEvidence(KnowledgeClaim $claim): bool
    {
        $blocked = false;
        $eligible = false;
        foreach ($this->evidence->listByClaim($claim->canonicalId) as $item) {
            if (!$item instanceof Evidence || !$item->active || !$item->isPublic()) continue;
            $source = $this->sources->findByCanonicalId($item->sourceId);
            if ($source === null || !$source->active || !$source->isPublic()) continue;
            if ($this->sourceDisplayPolicy->allows($source)) $eligible = true;
            else $blocked = true;
        }
        return $blocked && !$eligible;
    }
    private function evidence(Evidence $item, ?Source $source = null): array { return ['source_title' => $source?->title, 'source_type' => $source?->sourceType, 'source_locator' => $source?->locator, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator]; }
}
