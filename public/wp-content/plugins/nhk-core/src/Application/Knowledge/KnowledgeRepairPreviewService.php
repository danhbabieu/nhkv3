<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Capture\KnowledgeRepairIntent;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Domain\Graph\NodeReference;

/** Read-only dependency and impact preview for one existing Knowledge claim. */
final class KnowledgeRepairPreviewService
{
    public function __construct(private KnowledgeRepository $claims, private EvidenceRepository $evidence, private ?GraphService $graph = null) {}

    public function preview(array $input): array
    {
        $repair = KnowledgeRepairIntent::fromArray($input);
        $claim = $this->claims->findByCanonicalId($repair->targetUuid);
        if ($claim === null) return ['status' => 'REVIEW_REQUIRED', 'reason' => 'KNOWLEDGE_REPAIR_TARGET_NOT_FOUND', 'target' => ['canonical_uuid' => $repair->targetUuid]];
        $edges = [];
        if ($this->graph !== null) {
            try {
                $node = new NodeReference('knowledge', $claim->canonicalId);
                foreach ([$this->graph->findOutgoing($node, null, 0, 100, true), $this->graph->findIncoming($node, null, 0, 100, true)] as $page) foreach ((array) ($page['items'] ?? []) as $edge) {
                    if (!is_object($edge)) continue;
                    $edges[] = ['uuid' => $edge->edge_uuid, 'source' => $edge->source->reference->endpoint_key, 'predicate' => $edge->predicate, 'target' => $edge->target->reference->endpoint_key, 'active' => $edge->isActive(), 'revision' => $edge->revision];
                }
            } catch (\Throwable) { return ['status' => 'REVIEW_REQUIRED', 'reason' => 'KNOWLEDGE_REPAIR_GRAPH_READBACK_UNAVAILABLE', 'target' => ['canonical_uuid' => $claim->canonicalId]]; }
        }
        $evidence = array_map(static fn ($item): array => ['canonical_id' => $item->canonicalId, 'claim_id' => $item->claimId, 'source_id' => $item->sourceId, 'active' => $item->active, 'revision' => $item->revision], $this->evidence->listByClaim($claim->canonicalId, true));
        $manualReview = $repair->operation === 'retire' && (array_filter($edges, static fn (array $edge): bool => $edge['active'] === true) !== [] || array_filter($evidence, static fn (array $row): bool => $row['active'] === true) !== []);
        $revisionOk = $claim->revision === $repair->expectedRevision;
        $proposed = ['text' => $repair->text ?? $claim->claimText, 'claim_type' => $repair->claimType ?? $claim->claimType];
        return [
            'status' => !$revisionOk || $manualReview ? 'REVIEW_REQUIRED' : ($repair->operation === 'retire' ? 'SAFE_TO_RETIRE' : 'SAFE_TO_UPDATE'),
            'target' => ['canonical_uuid' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'current_revision' => $claim->revision, 'current_text' => $claim->claimText, 'current_type' => $claim->claimType, 'active' => $claim->active],
            'proposed_delta' => $proposed,
            'graph_dependencies' => $edges,
            'evidence_dependencies' => $evidence,
            'projection_public_impact' => ['currently_public' => $claim->isPublic(), 'operation' => $repair->operation, 'stable_key_changes' => false, 'new_knowledge_minted' => false],
            'blockers' => array_values(array_filter([$revisionOk ? null : 'KNOWLEDGE_REPAIR_REVISION_CHANGED', $manualReview ? 'KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED' : null])),
        ];
    }

    public function readback(string $targetUuid): array
    {
        $claim = $this->claims->findByCanonicalId($targetUuid);
        if ($claim === null) return ['status' => 'REVIEW_REQUIRED', 'reason' => 'KNOWLEDGE_REPAIR_READBACK_NOT_FOUND'];
        $graph = [];
        if ($this->graph !== null) {
            $node = new NodeReference('knowledge', $claim->canonicalId);
            foreach ([$this->graph->findOutgoing($node, null, 0, 100, true), $this->graph->findIncoming($node, null, 0, 100, true)] as $page) foreach ((array) ($page['items'] ?? []) as $edge) if (is_object($edge)) $graph[] = ['uuid' => $edge->edge_uuid, 'source' => $edge->source->reference->endpoint_key, 'predicate' => $edge->predicate, 'target' => $edge->target->reference->endpoint_key, 'active' => $edge->isActive(), 'revision' => $edge->revision];
        }
        return ['status' => 'READ_BACK', 'knowledge' => ['canonical_id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'revision' => $claim->revision, 'text' => $claim->claimText, 'claim_type' => $claim->claimType, 'active' => $claim->active], 'graph_dependencies' => $graph, 'evidence_dependencies' => array_map(static fn ($item): array => ['canonical_id' => $item->canonicalId, 'claim_id' => $item->claimId, 'source_id' => $item->sourceId, 'active' => $item->active, 'revision' => $item->revision], $this->evidence->listByClaim($claim->canonicalId, true))];
    }
}
