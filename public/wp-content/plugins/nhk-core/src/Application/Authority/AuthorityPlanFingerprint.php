<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Domain\Governance\CommandCanonicalizer;

/** Deterministic binding of a plan and its complete execution contract. */
final class AuthorityPlanFingerprint
{
    /** @param array<string,mixed> $plan @param array<string,mixed> $contract */
    public static function compute(string $captureId, int $captureRevision, array $plan, array $contract = []): string
    {
        return hash('sha256', CommandCanonicalizer::canonicalize([
            'capture_id' => $captureId,
            'capture_revision' => $captureRevision,
            'candidate_ids' => self::candidateIds($plan),
            'candidates' => $plan,
            'canonical_uuid_revision_closure' => $contract['canonical_uuid_revision_closure'] ?? [],
            'relation_packets' => $contract['relation_packets'] ?? ($plan['relation_candidates'] ?? []),
            'dependency_closure' => $contract['dependency_closure'] ?? [],
            'documentation_version' => (string) ($contract['documentation_version'] ?? ''),
            'manifest_hash' => (string) ($contract['manifest_hash'] ?? ''),
            'authority_registry_fingerprint' => (string) ($contract['authority_registry_fingerprint'] ?? ''),
            'authority_registry_version' => (string) ($contract['authority_registry_version'] ?? ''),
            'predicate_registry_fingerprint' => (string) ($contract['predicate_registry_fingerprint'] ?? ''),
            'predicate_registry_version' => (string) ($contract['predicate_registry_version'] ?? ''),
            'stable_key_policy_version' => (string) ($contract['stable_key_policy_version'] ?? CanonicalAuthorityStableKeyPolicy::VERSION),
            'conversational_authority_policy_version' => (string) ($contract['conversational_authority_policy_version'] ?? ''),
            'generic_governance_policy_version' => (string) ($contract['generic_governance_policy_version'] ?? ''),
            'effective_governance_policy' => $contract['effective_governance_policy'] ?? null,
            'semantic_contract_version' => (string) ($contract['semantic_contract_version'] ?? ''),
        ]));
    }

    /** @return list<string> */
    private static function candidateIds(array $plan): array
    {
        $ids = [];
        foreach (['reuse', 'create_candidates', 'update_candidates', 'relation_candidates'] as $bucket) foreach ((array) ($plan[$bucket] ?? []) as $candidate) if (is_array($candidate) && isset($candidate['candidate_id'])) $ids[] = (string) $candidate['candidate_id'];
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); return $ids;
    }
}
