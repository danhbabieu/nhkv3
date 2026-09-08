<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Contracts\Projection\{ProjectionDependencyIndex, ProjectionRevisionStore};
use NHK\Core\Domain\Graph\NodeReference;

final class ProjectionInvalidationService
{
    public function __construct(private ProjectionDependencyIndex $dependencies, private ProjectionRevisionStore $store, private ?ClaimProjectionService $projections = null, private $claimImpactResolver = null, private $relationImpactResolver = null) {}

    /** @return array{status:string,impacted:list<array<string,mixed>>} */
    public function invalidate(string $kind, string $id, ?int $revision = null, array $additionalImpacted = []): array
    {
        try {
            $storage = $this->dependencies->status();
            if (($storage['status'] ?? 'unavailable') !== 'available') return ['status' => 'unavailable', 'reason' => $storage['reason'] ?? 'PROJECTION_STORAGE_UNAVAILABLE', 'impacted' => [], 'failures' => []];
            $storage = $this->store->status();
            if (($storage['status'] ?? 'unavailable') !== 'available') return ['status' => 'unavailable', 'reason' => $storage['reason'] ?? 'PROJECTION_STORAGE_UNAVAILABLE', 'impacted' => [], 'failures' => []];
        } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'PROJECTION_STORAGE_UNAVAILABLE', 'impacted' => [], 'failures' => []]; }
        $impacted = $this->dependencies->findByDependency($kind, $id);
        foreach ($additionalImpacted as $candidate) {
            if (!is_array($candidate)) continue;
            $candidate['kind'] = $kind; $candidate['id'] = $id;
            $duplicate = false;
            foreach ($impacted as $existing) if (($existing['node_uuid'] ?? '') === ($candidate['node_uuid'] ?? '') && ($existing['section_key'] ?? '') === ($candidate['section_key'] ?? '')) { $duplicate = true; break; }
            if (!$duplicate) $impacted[] = $candidate;
        }
        $nodes = [];
        $failures = [];
        foreach ($impacted as $item) {
            $node = trim((string) ($item['node_uuid'] ?? ''));
            if ($node === '') continue;
            $nodes[$node][] = (string) ($item['section_key'] ?? '');
        }
        foreach ($nodes as $node => $sections) {
            $dirtySections = array_values(array_filter(array_unique($sections)));
            $this->store->markDirty($node, $dirtySections);
            if ($this->projections !== null) {
                $type = '';
                foreach ($impacted as $item) if (($item['node_uuid'] ?? '') === $node) { $type = trim((string) ($item['node_type'] ?? '')); if ($type !== '') break; }
                if ($type !== '') {
                    try {
                        $candidate = $this->store->findCandidate($node) ?? $this->store->findPublished($node);
                        $seo = $candidate?->payload['seo'] ?? [];
                        $this->projections->rebuild(new NodeReference($type, $node), (string) ($seo['canonical_url'] ?? ''), (string) ($seo['h1'] ?? ''), ['dirty_sections' => $dirtySections]);
                    } catch (\Throwable $error) { $failures[] = ['node_uuid' => $node, 'reason' => $error->getMessage()]; }
                }
            }
        }
        return ['status' => $failures === [] ? 'invalidated' : 'partial', 'impacted' => $impacted, 'failures' => $failures];
    }

    public function invalidateClaim(string $claimUuid, ?int $revision = null): array
    {
        $additional = is_callable($this->claimImpactResolver) ? (array) ($this->claimImpactResolver)($claimUuid) : [];
        return $this->invalidate('claim', $claimUuid, $revision, $additional);
    }
    public function invalidateRelation(string $edgeUuid, ?int $revision = null): array
    {
        $additional = is_callable($this->relationImpactResolver) ? (array) ($this->relationImpactResolver)($edgeUuid) : [];
        return $this->invalidate('relation', $edgeUuid, $revision, $additional);
    }
}
