<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Contracts\Projection\{ProjectionDependencyIndex, ProjectionRevisionStore};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};
use NHK\Core\Shared\Uuid\UuidCodec;

final class ClaimProjectionService
{
    public function __construct(
        private LiveLedgerProjectionBuilder $ledger,
        private ProjectionRevisionStore $store,
        private ?SeoProjectionBuilder $seo = null,
        private ?ProjectionDependencyIndex $dependencies = null,
    ) {}

    /** @return array<string,mixed> */
    public function getLedger(string $nodeUuid, array $options = []): array
    {
        if (trim((string) ($options['node_type'] ?? '')) !== 'wp_post' && !UuidCodec::isValid($nodeUuid)) return ['status' => 'blocked', 'reason' => 'INVALID_NODE_UUID', 'sections' => []];
        if (($storage = $this->storageStatus()) !== null) return ['status' => 'unavailable', 'reason' => $storage['reason'], 'sections' => [], 'claim_count' => null];
        try { $candidate = $this->store->findCandidate($nodeUuid); $published = $candidate === null ? $this->store->findPublished($nodeUuid) : null; } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'PROJECTION_STORAGE_UNAVAILABLE', 'sections' => []]; }
        $revision = $candidate ?? $published;
        if ($revision !== null && !isset($options['force_rebuild'])) {
            $ledger = $revision->payload['ledger'] ?? ['status' => 'unavailable', 'reason' => 'PROJECTION_PAYLOAD_MISSING', 'sections' => []];
            if (is_array($ledger)) $ledger = $this->pageLedger($ledger, (int) ($options['page'] ?? 1), (int) ($options['per_section'] ?? 50));
            return is_array($ledger) ? $this->publicLedger($ledger) : ['status' => 'unavailable', 'reason' => 'PROJECTION_PAYLOAD_INVALID', 'sections' => []];
        }
        return ['status' => 'unavailable', 'reason' => $revision === null ? 'PROJECTION_NOT_BUILT' : 'PROJECTION_PAGE_REQUIRES_REBUILD', 'sections' => []];
    }

    /** @return array<string,mixed>|null */
    public function getPublishedSeoProjection(string $nodeUuid): ?array
    {
        if ($this->storageStatus() !== null) return null;
        try { $published = $this->store->findPublished($nodeUuid); } catch (\Throwable) { return null; }
        $seo = $published?->payload['seo'] ?? null;
        return is_array($seo) ? $seo : null;
    }

    /** @return array<string,mixed> */
    public function getProjectionStatus(string $nodeUuid): array
    {
        if (($storage = $this->storageStatus()) !== null) return ['node_uuid' => $nodeUuid, 'status' => 'unavailable', 'reason' => $storage['reason'], 'schema_status' => $storage['status'], 'missing_tables' => $storage['missing_tables'], 'published_revision' => null, 'candidate_revision' => null, 'dirty_sections' => [], 'claim_count' => null, 'validation' => 'unavailable'];
        try { $published = $this->store->findPublished($nodeUuid); $candidate = $this->store->findCandidate($nodeUuid); } catch (\Throwable) { return ['node_uuid' => $nodeUuid, 'status' => 'unavailable', 'published_revision' => null, 'candidate_revision' => null, 'dirty_sections' => [], 'validation' => 'unavailable']; }
        return ['node_uuid' => $nodeUuid, 'published_revision' => $published?->revision, 'candidate_revision' => $candidate?->revision, 'status' => $candidate?->status ?? ($published?->status ?? 'missing'), 'schema_status' => 'available', 'reason' => null, 'missing_tables' => [], 'dirty_sections' => $candidate?->dirtySections ?? [], 'claim_count' => (int) ($candidate?->payload['ledger']['claim_count'] ?? $published?->payload['ledger']['claim_count'] ?? 0), 'validation' => $candidate?->status === ProjectionStatus::READY ? 'ready' : ($candidate === null ? 'missing' : $candidate->status)];
    }

    public function rebuild(NodeReference $node, string $canonicalUrl = '', string $h1 = '', array $options = []): ProjectionRevision
    {
        if ($node->endpoint_type !== 'wp_post' && !UuidCodec::isValid($node->endpoint_key)) throw new \InvalidArgumentException('INVALID_NODE_UUID');
        if (($storage = $this->storageStatus()) !== null) throw new \RuntimeException((string) $storage['reason']);
        $ledger = $this->ledger->build($node, $options + ['force_rebuild' => true, 'materialize_all' => true]);
        if (($ledger['status'] ?? '') !== 'available') throw new \RuntimeException((string) ($ledger['reason'] ?? 'PROJECTION_BUILD_FAILED'));
        $projectionDependencies = (array) ($ledger['_dependencies'] ?? []);
        unset($ledger['_dependencies']);
        $seo = ($this->seo ??= new SeoProjectionBuilder())->build($ledger, $canonicalUrl, $h1);
        $revision = new ProjectionRevision($node->endpoint_key, 1, ProjectionStatus::CANDIDATE, (string) $ledger['input_hash'], (string) $ledger['claim_set_hash'], (string) $ledger['graph_hash'], (int) ($options['policy_revision'] ?? GraphProjectionPolicy::REVISION), (int) ($seo['template_revision'] ?? SeoProjectionBuilder::TEMPLATE_REVISION), ['ledger' => $ledger, 'seo' => $seo], (array) ($options['dirty_sections'] ?? []), (string) ($ledger['generated_at'] ?? gmdate('c')));
        $saved = $this->store->saveCandidate($revision);
        if ($this->dependencies !== null) {
            $this->dependencies->removeForNode($node->endpoint_key);
            foreach ($projectionDependencies as $dependency) if (is_array($dependency) && trim((string) ($dependency['id'] ?? '')) !== '') $this->dependencies->add($dependency + ['node_uuid' => $node->endpoint_key, 'node_type' => $node->endpoint_type]);
        }
        return $saved;
    }

    public function validate(string $nodeUuid, int $revision): ProjectionRevision
    {
        $candidate = $this->store->findCandidate($nodeUuid);
        if ($candidate === null || $candidate->revision !== $revision) throw new \RuntimeException('PROJECTION_CANDIDATE_NOT_FOUND');
        $seo = $candidate->payload['seo'] ?? [];
        if (!is_array($seo) || trim((string) ($seo['canonical_url'] ?? '')) === '' || trim((string) ($seo['h1'] ?? '')) === '') throw new \RuntimeException('PROJECTION_STABLE_CORE_MISSING');
        return $this->store->markReady($nodeUuid, $revision);
    }

    public function publish(string $nodeUuid, int $revision): ProjectionRevision { return $this->store->publish($nodeUuid, $revision); }
    public function discard(string $nodeUuid, int $revision): void { $this->store->discard($nodeUuid, $revision); }

    /** @param array<string,mixed> $ledger @return array<string,mixed> */
    private function pageLedger(array $ledger, int $page, int $perSection): array
    {
        $page = max(1, $page); $perSection = min(100, max(1, $perSection));
        foreach ((array) ($ledger['sections'] ?? []) as $i => $section) {
            if (!is_array($section)) continue;
            $all = (array) ($section['claims'] ?? []); $total = (int) ($section['total_count'] ?? count($all));
            $section['claims'] = array_slice($all, ($page - 1) * $perSection, $perSection); $section['visible_count'] = count($section['claims']); $section['claim_count'] = $total; $section['page'] = $page; $section['per_page'] = $perSection; $section['page_count'] = (int) ceil($total / $perSection); $section['total_count'] = $total; $ledger['sections'][$i] = $section;
        }
        return $ledger;
    }

    /** @param array<string,mixed> $ledger @return array<string,mixed> */
    private function publicLedger(array $ledger): array
    {
        foreach ((array) ($ledger['sections'] ?? []) as $i => $section) {
            if (!is_array($section)) continue;
            foreach ((array) ($section['claims'] ?? []) as $j => $claim) {
                if (!is_array($claim)) continue;
                foreach (['claim_uuid', 'canonical_subject_uuid'] as $key) unset($claim[$key]);
                if (is_array($claim['source_context'] ?? null)) unset($claim['source_context']['node_uuid']);
                $section['claims'][$j] = $claim;
            }
            foreach ((array) ($section['clusters'] ?? []) as $j => $cluster) {
                if (!is_array($cluster)) continue;
                foreach (['representative_claim_id', 'supporting_claim_ids', 'contradictory_claim_ids'] as $key) unset($cluster[$key]);
                $section['clusters'][$j] = $cluster;
            }
            $ledger['sections'][$i] = $section;
        }
        return $ledger;
    }

    /** @return array{status:string,reason:string,missing_tables:list<string>}|null */
    private function storageStatus(): ?array
    {
        try {
            $status = $this->store->status();
            if (($status['status'] ?? 'unavailable') !== 'available') return ['status' => 'unavailable', 'reason' => (string) ($status['reason'] ?? 'PROJECTION_STORAGE_UNAVAILABLE'), 'missing_tables' => (array) ($status['missing_tables'] ?? [])];
            if ($this->dependencies !== null) {
                $status = $this->dependencies->status();
                if (($status['status'] ?? 'unavailable') !== 'available') return ['status' => 'unavailable', 'reason' => (string) ($status['reason'] ?? 'PROJECTION_STORAGE_UNAVAILABLE'), 'missing_tables' => (array) ($status['missing_tables'] ?? [])];
            }
            return null;
        } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'PROJECTION_STORAGE_UNAVAILABLE', 'missing_tables' => []]; }
    }
}
