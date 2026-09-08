<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Projection\{ClaimProjectionScope, ProjectedClaim, ProjectionContext};

final class ClaimScopeResolver
{
    public const MAX_DISTANCE = 2;

    public function __construct(
        private KnowledgeRepository $claims,
        private GraphService $graph,
        private GraphProjectionPolicy $policy,
        private ?ClaimClassifier $classifier = null,
        private ?NodeProjectionProfileRegistry $profiles = null,
        private ?EvidenceRepository $evidence = null,
        private ?SourceRepository $sources = null,
        private $labelResolver = null,
    ) {}

    /** @return array{status:string,node:array<string,string>,direct:list<ProjectedClaim>,related:list<ProjectedClaim>,items:list<ProjectedClaim>,reason?:string} */
    public function resolve(NodeReference $node, int $maxDistance = self::MAX_DISTANCE): array
    {
        if ($maxDistance < 0 || $maxDistance > self::MAX_DISTANCE) return ['status' => 'unsupported', 'node' => ['type' => $node->endpoint_type, 'uuid' => $node->endpoint_key], 'direct' => [], 'related' => [], 'items' => [], 'reason' => 'BOUNDS_INVALID'];
        $classifier = $this->classifier ??= new ClaimClassifier();
        $profiles = $this->profiles ??= new NodeProjectionProfileRegistry();
        try { $claims = $this->claims->list(); } catch (\Throwable) { return ['status' => 'unavailable', 'node' => ['type' => $node->endpoint_type, 'uuid' => $node->endpoint_key], 'direct' => [], 'related' => [], 'items' => [], 'reason' => 'KNOWLEDGE_UNAVAILABLE']; }

        $bySubject = [];
        foreach ($claims as $claim) {
            if (!$claim instanceof KnowledgeClaim || !ClaimProjectionVisibility::eligible($claim)) continue;
            [$subjectId, $subjectType] = $this->subject($claim);
            if ($subjectId === '') continue;
            if ($subjectType !== null && $subjectType !== $node->endpoint_type && $subjectId === $node->endpoint_key) continue;
            $category = $classifier->classify($claim);
            if (!$profiles->allows($node->endpoint_type, $category)) continue;
            $bySubject[$subjectId][] = [$claim, $category, $subjectType];
        }

        $direct = [];
        foreach ($bySubject[$node->endpoint_key] ?? [] as [$claim, $category, $subjectType]) {
            if ($subjectType !== null && $subjectType !== $node->endpoint_type) continue;
            $direct[] = $this->project($claim, $category, new ClaimProjectionScope($node->endpoint_key, $node->endpoint_key, ClaimProjectionScope::DIRECT), null);
        }

        $related = [];
        if ($maxDistance > 0) {
            $queue = [[$node, 0, []]];
            $visited = [$node->key() => true];
            while ($queue !== []) {
                [$current, $depth, $path] = array_shift($queue);
                if ($depth >= $maxDistance) continue;
                try { $page = $this->graph->findIncoming($current, null, 0, 200); } catch (\Throwable) { return ['status' => 'unavailable', 'node' => ['type' => $node->endpoint_type, 'uuid' => $node->endpoint_key], 'direct' => [], 'related' => [], 'items' => [], 'reason' => 'GRAPH_UNAVAILABLE']; }
                foreach ((array) ($page['items'] ?? []) as $edge) {
                    if (!$edge instanceof GraphEdge || !$edge->isActive()) continue;
                    $other = $edge->source->reference;
                    if (!$this->policy->allowsTraversal($current, 'incoming', $other, $edge->predicate)) continue;
                    $hop = ['source_type' => $other->endpoint_type, 'source_uuid' => $other->endpoint_key, 'predicate' => $edge->predicate, 'target_type' => $current->endpoint_type, 'target_uuid' => $current->endpoint_key, 'direction' => 'incoming', 'edge_uuid' => $edge->edge_uuid, 'edge_revision' => $edge->revision];
                    $semanticPath = array_merge([$hop], $path);
                    $distance = $depth + 1;
                    foreach ($bySubject[$other->endpoint_key] ?? [] as [$claim, $category, $subjectType]) {
                        if ($subjectType !== null && $subjectType !== $other->endpoint_type) continue;
                        $state = ClaimProjectionVisibility::status($claim);
                        if ($subjectType === null) $subjectType = $other->endpoint_type;
                        if ($this->policy->allowsPath($subjectType, $node->endpoint_type, $semanticPath, $category, $state, $this->hasEligibleEvidence($claim))) {
                            $context = new ProjectionContext($other->endpoint_key, $other->endpoint_type, $this->label($claim, $other), (string) ($semanticPath[0]['predicate'] ?? ''), $semanticPath);
                            $related[] = $this->project($claim, $category, new ClaimProjectionScope($node->endpoint_key, $other->endpoint_key, ClaimProjectionScope::RELATED, $distance, $semanticPath), $context);
                        }
                    }
                    if (!isset($visited[$other->key()])) { $visited[$other->key()] = true; $queue[] = [$other, $distance, $semanticPath]; }
                }
            }
        }

        $dedup = [];
        foreach (array_merge($direct, $related) as $item) {
            $key = $item->claim->canonicalId . '|' . $item->scope->scope . '|' . $item->scope->graphDistance;
            if (!isset($dedup[$key])) $dedup[$key] = $item;
        }
        return ['status' => 'available', 'node' => ['type' => $node->endpoint_type, 'uuid' => $node->endpoint_key], 'direct' => $direct, 'related' => $related, 'items' => array_values($dedup)];
    }

    /** @return list<array{node_uuid:string,node_type:string,section_key:string,scope:string,graph_distance:int}> */
    public function impactNodesForClaim(string $claimUuid): array
    {
        try { $claim = $this->claims->findByCanonicalId($claimUuid); } catch (\Throwable) { return []; }
        if (!$claim instanceof KnowledgeClaim || !ClaimProjectionVisibility::eligible($claim)) return [];
        [$subjectUuid, $subjectType] = $this->subject($claim);
        if ($subjectUuid === '' || $subjectType === null) return [];
        $category = ($this->classifier ??= new ClaimClassifier())->classify($claim);
        if (!($this->profiles ??= new NodeProjectionProfileRegistry())->allows($subjectType, $category)) return [];
        $impacts = [['node_uuid' => $subjectUuid, 'node_type' => $subjectType, 'section_key' => $category, 'scope' => 'direct', 'graph_distance' => 0]];
        $queue = [[new NodeReference($subjectType, $subjectUuid), 0, []]];
        $visited = [$subjectType . ':' . $subjectUuid => true];
        while ($queue !== []) {
            [$current, $depth, $path] = array_shift($queue);
            if ($depth >= self::MAX_DISTANCE) continue;
            try { $page = $this->graph->findIncoming($current, null, 0, 200); } catch (\Throwable) { return $impacts; }
            foreach ((array) ($page['items'] ?? []) as $edge) {
                if (!$edge instanceof GraphEdge || !$edge->isActive()) continue;
                $other = $edge->source->reference;
                if (!$this->policy->allowsTraversal($current, 'incoming', $other, $edge->predicate)) continue;
                $hop = ['source_type' => $other->endpoint_type, 'source_uuid' => $other->endpoint_key, 'predicate' => $edge->predicate, 'target_type' => $current->endpoint_type, 'target_uuid' => $current->endpoint_key, 'direction' => 'incoming', 'edge_uuid' => $edge->edge_uuid, 'edge_revision' => $edge->revision];
                $semanticPath = array_merge([$hop], $path);
                $distance = $depth + 1;
                if ($this->policy->allowsPath($subjectType, $other->endpoint_type, $semanticPath, $category, ClaimProjectionVisibility::status($claim), $this->hasEligibleEvidence($claim)) && ($this->profiles ??= new NodeProjectionProfileRegistry())->allows($other->endpoint_type, $category)) {
                    $impacts[] = ['node_uuid' => $other->endpoint_key, 'node_type' => $other->endpoint_type, 'section_key' => $category, 'scope' => 'related', 'graph_distance' => $distance];
                }
                if (!isset($visited[$other->key()])) { $visited[$other->key()] = true; $queue[] = [$other, $distance, $semanticPath]; }
            }
        }
        return $impacts;
    }

    /** @return list<array{node_uuid:string,node_type:string,section_key:string,scope:string,graph_distance:int}> */
    public function impactNodesForRelation(string $edgeUuid): array
    {
        try { $edge = $this->graph->findByUuid($edgeUuid); } catch (\Throwable) { return []; }
        if (!$edge instanceof GraphEdge) return [];
        $profiles = $this->profiles ??= new NodeProjectionProfileRegistry();
        $impacts = [];
        $queue = [[$edge->target->reference, 0]];
        $visited = [$edge->target->reference->key() => true];
        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);
            foreach ($profiles->categoriesFor($current->endpoint_type) as $category) {
                $impacts[] = ['node_uuid' => $current->endpoint_key, 'node_type' => $current->endpoint_type, 'section_key' => $category, 'scope' => $depth === 0 ? 'direct' : 'related', 'graph_distance' => $depth];
            }
            if ($depth >= self::MAX_DISTANCE) continue;
            try { $page = $this->graph->findIncoming($current, null, 0, 200); } catch (\Throwable) { break; }
            foreach ((array) ($page['items'] ?? []) as $incoming) {
                if (!$incoming instanceof GraphEdge || !$incoming->isActive()) continue;
                $other = $incoming->source->reference;
                if (!$this->policy->allowsTraversal($current, 'incoming', $other, $incoming->predicate)) continue;
                if (!isset($visited[$other->key()])) { $visited[$other->key()] = true; $queue[] = [$other, $depth + 1]; }
            }
        }
        return $impacts;
    }

    /** @return array{0:string,1:?string} */
    private function subject(KnowledgeClaim $claim): array
    {
        $metadata = $claim->provenance['metadata'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $id = '';
        foreach (['subject_uuid', 'subject_id', 'canonical_subject_uuid', 'canonical_subject_id'] as $key) if (trim((string) ($metadata[$key] ?? '')) !== '') { $id = trim((string) $metadata[$key]); break; }
        if ($id === '') $id = trim((string) ($claim->provenance['subject_uuid'] ?? $claim->provenance['subject_id'] ?? ''));
        $type = trim((string) ($metadata['subject_type'] ?? $claim->provenance['subject_type'] ?? ''));
        return [$id, $type !== '' ? $type : null];
    }

    private function project(KnowledgeClaim $claim, string $category, ClaimProjectionScope $scope, ?ProjectionContext $context): ProjectedClaim
    {
        $text = $claim->claimText;
        if ($context !== null && $context->nodeLabel !== null && trim($context->nodeLabel) !== '') {
            $label = trim($context->nodeLabel);
            $rest = preg_replace('/^' . preg_quote($label, '/') . '\s*[:\-–—,]?\s*/iu', '', $text);
            $rest = is_string($rest) && trim($rest) !== '' ? trim($rest) : $text;
            $prefix = match ($context->nodeType) { 'variant' => 'Ở biến thể ', 'model' => 'Ở model ', 'brand' => 'Trong dòng ', default => 'Theo ' };
            $text = $prefix . $label . ', ' . $rest;
        }
        return new ProjectedClaim($claim, $category, $scope, ClaimProjectionVisibility::status($claim), $context, $text);
    }

    private function label(KnowledgeClaim $claim, NodeReference $node): ?string
    {
        if (is_callable($this->labelResolver)) { $label = ($this->labelResolver)($node); if (is_string($label) && trim($label) !== '') return trim($label); }
        $metadata = $claim->provenance['metadata'] ?? [];
        return is_array($metadata) && trim((string) ($metadata['subject_label'] ?? '')) !== '' ? trim((string) $metadata['subject_label']) : null;
    }

    private function hasEligibleEvidence(KnowledgeClaim $claim): bool
    {
        if ($this->evidence === null || $this->sources === null) return true;
        try {
            foreach ($this->evidence->listByClaim($claim->canonicalId) as $item) {
                $source = $this->sources->findByCanonicalId($item->sourceId);
                if ($item->active && $item->isPublic() && $source !== null && $source->active && $source->isPublic()) return true;
            }
        } catch (\Throwable) { return false; }
        return false;
    }
}
