<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Video\VideoIdentityReader;
use NHK\Core\Domain\Graph\NodeReference;

/** Read-only owner-specific gate for a future Knowledge duplicate repair. */
final class KnowledgeDuplicateReconciliationPlanner
{
    public function __construct(private KnowledgeRepository $claims, private EvidenceRepository $evidence, private ?GraphService $graph = null, private ?VideoIdentityReader $videoIdentityReader = null) {}

    /** @return array<string,mixed> */
    public function plan(array $candidate): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', (array) ($candidate['canonical_ids'] ?? [])))));
        $blockers = [];
        if (count($ids) !== 2) $blockers[] = 'KNOWLEDGE_RECONCILIATION_TWO_RECORDS_REQUIRED';
        $classification = strtoupper(trim((string) ($candidate['classification'] ?? '')));
        if (!in_array($classification, ['DEFINITE_DUPLICATE', 'HIGH_CONFIDENCE_EQUIVALENT'], true)) $blockers[] = 'KNOWLEDGE_RECONCILIATION_CLASSIFICATION_NOT_EXECUTABLE';
        $claims = [];
        foreach ($ids as $id) {
            $claim = $this->claims->findByCanonicalId($id);
            if ($claim === null) $blockers[] = 'KNOWLEDGE_RECONCILIATION_RECORD_NOT_FOUND';
            else $claims[$id] = $claim;
        }
        if (count($claims) !== 2) return $this->review($ids, $blockers);

        $identities = [];
        foreach ($claims as $id => $claim) {
            $identity = KnowledgeClaimIdentity::resolveClaim($claim, $this->videoIdentityReader);
            $identities[$id] = $identity;
            if ($identity->status() !== KnowledgeClaimIdentityResolution::RESOLVED) $blockers[] = 'KNOWLEDGE_IDENTITY_' . $identity->status();
            if (!$claim->active) $blockers[] = 'KNOWLEDGE_RECONCILIATION_RETIRED_CLAIM_REVIEW_REQUIRED';
        }
        $firstIdentity = $identities[$ids[0]];
        $secondIdentity = $identities[$ids[1]];
        if (!$firstIdentity->equivalentTo($secondIdentity)) $blockers[] = 'KNOWLEDGE_IDENTITY_NOT_EQUAL';
        $policy = (string) ($candidate['identity_policy'] ?? '');
        if ($policy !== KnowledgeClaimIdentityResolution::POLICY_VERSION) $blockers[] = 'KNOWLEDGE_IDENTITY_POLICY_STALE';
        if ((string) ($candidate['identity_fingerprint'] ?? '') !== $firstIdentity->fingerprint()) $blockers[] = 'KNOWLEDGE_IDENTITY_FINGERPRINT_STALE';

        $revisions = [];
        foreach ($claims as $id => $claim) $revisions[$id] = $claim->revision;
        if ($this->canonicalize((array) ($candidate['record_revisions'] ?? [])) !== $this->canonicalize($revisions)) $blockers[] = 'KNOWLEDGE_RECONCILIATION_REVISION_STALE';
        $dependencies = $this->dependencySnapshot($ids);
        $dependencyFingerprint = $this->fingerprint($dependencies);
        if ((string) ($candidate['dependency_fingerprint'] ?? '') !== $dependencyFingerprint) $blockers[] = 'KNOWLEDGE_DEPENDENCY_FINGERPRINT_STALE';

        if ($blockers !== []) return $this->review($ids, array_values(array_unique($blockers)), $firstIdentity, $revisions, $dependencyFingerprint);
        return [
            'status' => 'SAFE_TO_RECONCILE',
            'apply' => false,
            'commands' => [[
                'operation' => 'retire',
                'canonical_id' => $ids[1],
                'expected_revision' => $revisions[$ids[1]],
                'requires_governed_dependency_review' => true,
            ]],
            'identity_policy' => $firstIdentity->policyVersion(),
            'identity_fingerprint' => $firstIdentity->fingerprint(),
            'record_revisions' => $revisions,
            'dependency_fingerprint' => $dependencyFingerprint,
            'blockers' => [],
        ];
    }

    /** @param list<string> $ids @param list<string> $blockers @return array<string,mixed> */
    private function review(array $ids, array $blockers, ?KnowledgeClaimIdentityResolution $identity = null, array $revisions = [], string $dependencyFingerprint = ''): array
    {
        return [
            'status' => 'REVIEW_REQUIRED',
            'apply' => false,
            'commands' => [],
            'identity_policy' => $identity?->policyVersion() ?? KnowledgeClaimIdentityResolution::POLICY_VERSION,
            'identity_fingerprint' => $identity?->fingerprint() ?? '',
            'record_revisions' => $revisions,
            'dependency_fingerprint' => $dependencyFingerprint,
            'canonical_ids' => $ids,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @param list<string> $ids @return array<string,mixed> */
    private function dependencySnapshot(array $ids): array
    {
        $evidence = [];
        foreach ($ids as $claimId) foreach ($this->evidence->listByClaim($claimId, true) as $item) {
            $evidence[] = ['canonical_id' => $item->canonicalId, 'claim_id' => $item->claimId, 'source_id' => $item->sourceId, 'active' => $item->active, 'revision' => $item->revision];
        }
        usort($evidence, static fn (array $left, array $right): int => strcmp((string) $left['canonical_id'], (string) $right['canonical_id']));
        $graph = [];
        if ($this->graph !== null) foreach ($ids as $id) {
            try {
                $node = new NodeReference('knowledge', $id);
                foreach ([$this->graph->findOutgoing($node, null, 0, 100, true), $this->graph->findIncoming($node, null, 0, 100, true)] as $page) foreach ((array) ($page['items'] ?? []) as $edge) if (is_object($edge)) $graph[] = ['uuid' => $edge->edge_uuid, 'revision' => $edge->revision, 'active' => $edge->isActive()];
            } catch (\Throwable) {
                return ['graph_readback' => 'UNAVAILABLE', 'evidence' => $evidence];
            }
        }
        usort($graph, static fn (array $left, array $right): int => strcmp((string) $left['uuid'], (string) $right['uuid']));
        return ['graph' => $graph, 'evidence' => $evidence];
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key => $child) $value[$key] = $this->canonicalize($child);
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value);
        return $value;
    }
}
