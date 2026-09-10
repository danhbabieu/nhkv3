<?php
declare(strict_types=1);

namespace NHK\Core\Application\Collector;

use NHK\Core\Application\Governance\{ControlledApplyService, GovernanceService};
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Knowledge\{CollectorFacetRegistry, KnowledgeClaim};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Plans and submits the governed, facet-only maintenance operation. */
final class CollectorFacetMaintenanceService
{
    public const OPERATION = 'collector_facet_update';

    public function __construct(
        private KnowledgeRepository $claims,
        private $branchReader,
        private ?GovernanceService $governance = null,
        private ?ControlledApplyService $controlledApply = null,
    ) {}

    /** @param list<array<string,mixed>> $candidates */
    public function plan(string $classificationId, array $candidates = []): array
    {
        if (!UuidCodec::isValid($classificationId)) return ['status' => 'blocked', 'reason_code' => 'CLASSIFICATION_UUID_INVALID'];
        $branch = $this->readBranch($classificationId);
        if (($branch['status'] ?? '') !== 'available') return ['status' => 'blocked', 'reason_code' => (string) ($branch['reason'] ?? 'BRANCH_KNOWLEDGE_UNAVAILABLE'), 'classification_uuid' => $classificationId];
        $unique = [];
        foreach ((array) ($branch['claims'] ?? []) as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$claim->active || !$claim->isPublic()) continue;
            $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
            if (($metadata['subject_id'] ?? '') !== '' && (string) $metadata['subject_id'] !== $classificationId) continue;
            $unique[$claim->canonicalId] = $claim;
        }
        $candidateMap = [];
        foreach ($candidates as $candidate) if (is_array($candidate) && trim((string) ($candidate['knowledge_uuid'] ?? '')) !== '') $candidateMap[(string) $candidate['knowledge_uuid']] = $candidate;
        $breakdown = array_fill_keys(CollectorFacetRegistry::all(), 0);
        $valid = $unresolved = $invalid = $proposed = $blocked = $noOp = [];
        foreach ($unique as $claim) {
            $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
            $facet = CollectorFacetRegistry::resolve($metadata);
            if ($facet !== '') {
                $valid[] = $claim->canonicalId;
                $breakdown[$facet]++;
                continue;
            }
            $candidate = $candidateMap[$claim->canonicalId] ?? null;
            if ($candidate === null) { $unresolved[] = $claim->canonicalId; continue; }
            $newFacet = trim((string) ($candidate['proposed_facet'] ?? ''));
            $confidence = (string) ($candidate['confidence'] ?? 'unresolved');
            $deterministic = ($confidence === 'high') || ($confidence === 'medium' && ($candidate['deterministic'] ?? false) === true);
            if (!CollectorFacetRegistry::isValidForScope($newFacet, (string) ($metadata['scope'] ?? '')) || !in_array($confidence, ['high', 'medium'], true) || !$deterministic) {
                $invalid[] = $claim->canonicalId;
                continue;
            }
            if ((int) ($candidate['expected_revision'] ?? 0) !== $claim->revision || (string) ($candidate['stable_key'] ?? $claim->stableKey) !== $claim->stableKey || ($candidate['claim_text_unchanged'] ?? true) !== true || ($candidate['identity_unchanged'] ?? true) !== true) {
                $blocked[] = $claim->canonicalId;
                continue;
            }
            if (CollectorFacetRegistry::resolve($metadata) === $newFacet) { $noOp[] = $claim->canonicalId; continue; }
            $proposed[] = [
                'knowledge_uuid' => $claim->canonicalId,
                'expected_revision' => $claim->revision,
                'old_facet' => '',
                'new_facet' => $newFacet,
                'reason' => (string) ($candidate['reason'] ?? ''),
                'confidence' => $confidence,
            ];
        }
        return [
            'status' => 'available', 'operation' => self::OPERATION, 'classification_uuid' => $classificationId,
            'registry' => CollectorFacetRegistry::all(), 'total_branch_knowledge' => count($unique),
            'already_valid' => count($valid), 'candidates_high' => count(array_filter($proposed, static fn (array $item): bool => $item['confidence'] === 'high')),
            'candidates_medium' => count(array_filter($proposed, static fn (array $item): bool => $item['confidence'] === 'medium')),
            'unresolved' => $unresolved, 'invalid_stale' => $invalid, 'blocked' => $blocked, 'no_op' => $noOp,
            'proposed_changes' => $proposed, 'facet_breakdown' => $breakdown,
        ];
    }

    public function createProposal(string $classificationId, array $candidate): Proposal
    {
        if (!$this->governance) throw new \RuntimeException('COLLECTOR_FACET_GOVERNANCE_UNAVAILABLE');
        $branch = $this->readBranch($classificationId);
        if (($branch['status'] ?? '') !== 'available') throw new \RuntimeException((string) ($branch['reason'] ?? 'BRANCH_KNOWLEDGE_UNAVAILABLE'));
        $id = (string) ($candidate['knowledge_uuid'] ?? '');
        $claim = null;
        foreach ((array) ($branch['claims'] ?? []) as $item) if ($item instanceof KnowledgeClaim && $item->canonicalId === $id) { $claim = $item; break; }
        if (!$claim) throw new \RuntimeException('COLLECTOR_FACET_TARGET_OUTSIDE_BRANCH');
        $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
        $facet = trim((string) ($candidate['proposed_facet'] ?? ''));
        if (!CollectorFacetRegistry::isValidForScope($facet, (string) ($metadata['scope'] ?? ''))) throw new \RuntimeException('COLLECTOR_FACET_INVALID');
        if ((int) ($candidate['expected_revision'] ?? 0) !== $claim->revision) throw new \RuntimeException('COLLECTOR_FACET_STALE_REVISION');
        $payload = [
            'field' => 'provenance.metadata.' . CollectorFacetRegistry::METADATA_KEY,
            'collector_facet' => $facet, 'classification_uuid' => $classificationId,
            'knowledge_uuid' => $claim->canonicalId, 'stable_key' => $claim->stableKey,
            'claim_text_sha256' => hash('sha256', $claim->claimText), 'claim_type' => $claim->claimType,
            'scope' => (string) ($metadata['scope'] ?? ''), 'provenance_sha256' => self::fingerprint($claim->provenance),
            'dependency_revisions' => [(string) $classificationId => (int) ($branch['classification_revision'] ?? 1)],
        ];
        $contentFingerprint = self::fingerprint(['operation' => self::OPERATION, 'payload' => $payload, 'reason' => (string) ($candidate['reason'] ?? ''), 'confidence' => (string) ($candidate['confidence'] ?? '')]);
        $dependencyFingerprint = self::fingerprint($payload['dependency_revisions']);
        $idempotency = 'collector-facet:' . $claim->canonicalId . ':' . $claim->revision . ':' . $facet . ':' . substr($contentFingerprint, 0, 16);
        return $this->governance->create(new Proposal(
            UuidCodec::newV7(), $claim->canonicalId, self::OPERATION, $payload, $contentFingerprint, $claim->revision, $dependencyFingerprint,
            actor: function_exists('get_current_user_id') ? (string) get_current_user_id() : '0', idempotencyKey: $idempotency,
            targetUuid: $claim->canonicalId, entityType: 'knowledge',
        ));
    }

    /** @param list<string> $proposalIds */
    public function applyBatch(array $proposalIds): array
    {
        if (!$this->controlledApply) throw new \RuntimeException('COLLECTOR_FACET_APPLY_UNAVAILABLE');
        $results = [];
        foreach ($proposalIds as $proposalId) {
            try { $results[] = ['proposal_id' => $proposalId, 'status' => 'applied'] + $this->controlledApply->apply($proposalId); }
            catch (\Throwable $error) { $results[] = ['proposal_id' => $proposalId, 'status' => 'failed', 'reason_code' => $error->getMessage()]; }
        }
        return $results;
    }

    /** @return array<string,mixed> */
    private function readBranch(string $classificationId): array
    {
        if (!is_callable($this->branchReader)) return ['status' => 'unavailable', 'reason' => 'COLLECTOR_FACET_BRANCH_READER_UNAVAILABLE'];
        try { $branch = ($this->branchReader)($classificationId); } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_UNAVAILABLE']; }
        return is_array($branch) ? $branch : ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_UNAVAILABLE'];
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
