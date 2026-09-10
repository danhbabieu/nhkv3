<?php
declare(strict_types=1);

namespace NHK\Core\Application\Collector;

use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Canonical Governance executor for the collector facet-only operation. */
final class CollectorFacetMaintenanceExecutor
{
    public function __construct(private KnowledgeService $knowledge, private $branchReader) {}

    public function __invoke(Proposal $proposal): KnowledgeClaim
    {
        if ($proposal->entityType !== 'knowledge' || $proposal->operation !== CollectorFacetMaintenanceService::OPERATION) throw new \InvalidArgumentException('COLLECTOR_FACET_OPERATION_INVALID');
        $payload = $proposal->payload;
        $allowed = ['field', 'collector_facet', 'classification_uuid', 'knowledge_uuid', 'stable_key', 'claim_text_sha256', 'claim_type', 'scope', 'provenance_sha256', 'dependency_revisions'];
        if (array_diff(array_keys($payload), $allowed) !== []) throw new \InvalidArgumentException('COLLECTOR_FACET_PAYLOAD_FORBIDDEN_FIELD');
        if (($payload['field'] ?? '') !== 'provenance.metadata.collector_facet' || (string) ($payload['knowledge_uuid'] ?? '') !== ($proposal->targetUuid ?: $proposal->subjectId)) throw new \InvalidArgumentException('COLLECTOR_FACET_BINDING_INVALID');
        $branch = is_callable($this->branchReader) ? ($this->branchReader)((string) ($payload['classification_uuid'] ?? '')) : [];
        if (!is_array($branch) || ($branch['status'] ?? '') !== 'available') throw new \RuntimeException('COLLECTOR_FACET_BRANCH_UNAVAILABLE');
        $member = false;
        foreach ((array) ($branch['claims'] ?? []) as $claim) if ($claim instanceof KnowledgeClaim && $claim->canonicalId === ($proposal->targetUuid ?: $proposal->subjectId)) { $member = true; break; }
        if (!$member) throw new \RuntimeException('COLLECTOR_FACET_TARGET_OUTSIDE_BRANCH');
        return $this->knowledge->updateCollectorFacet((string) ($proposal->targetUuid ?: $proposal->subjectId), (string) ($payload['collector_facet'] ?? ''), (int) $proposal->expectedRevision, $payload);
    }
}
