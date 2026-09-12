<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Governance\GovernedAuthorityPlanExecutor;
use NHK\Core\Contracts\Authority\AuthorityCanonicalReader;
use NHK\Core\Contracts\Governance\GovernedAuthorityPlanApplier;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, ConversationalAuthorityPolicy};

/**
 * The single operator lifecycle for one Clock Type. Planning searches first;
 * applying delegates to the governed plan executor and then verifies the
 * canonical Authority read-back without owning a writer.
 */
final class ClockTypeCreationLifecycle
{
    public function __construct(
        private AuthorityIntentPlanner $planner,
        private GovernedAuthorityPlanApplier $applier,
        private AuthorityCanonicalReader $authority,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
    ) {}

    /** @return array<string,mixed> */
    public function plan(string $name, array $input = [], array $captureContext = []): array
    {
        $name = trim($name);
        if ($name === '') throw new \InvalidArgumentException('CLOCK_TYPE_NAME_REQUIRED');
        if (trim((string) ($input['text'] ?? $input['content'] ?? '')) === '') $input['text'] = 'Thêm loại ' . $name . '.';
        $plan = $this->planner->plan($input, $captureContext);
        $plan['lifecycle'] = [
            'name' => $name,
            'entity_type' => 'classification',
            'family' => 'clock_type',
            'reuse_first' => true,
            'mutation_boundary' => 'GOVERNED_AUTHORITY_PLAN_ONLY',
            'public_identity' => 'SEPARATE_GOVERNED_FLOW',
        ];
        if (isset($input['description']) && is_string($input['description'])) foreach ($plan['create_candidates'] as &$candidate) $candidate['description'] = trim($input['description']);
        unset($candidate);
        $plan['plan_fingerprint'] = AuthorityPlanFingerprint::compute(
            (string) ($captureContext['capture_id'] ?? ''),
            max(1, (int) ($captureContext['capture_revision'] ?? 1)),
            $plan,
            is_array($captureContext['contract'] ?? null) ? $captureContext['contract'] : (is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : []),
        );
        return $plan;
    }

    /** @return array<string,mixed> */
    public function apply(array $plan, string $approvedFingerprint, array $approvedCandidateIds, ConversationalAuthorityPolicy $policy, string $actor = '0'): array
    {
        $currentFingerprint = (string) ($plan['plan_fingerprint'] ?? '');
        $result = $this->applier->execute($plan, $approvedFingerprint, $currentFingerprint, $approvedCandidateIds, $policy, $actor);
        if (($result['status'] ?? '') !== 'APPLIED') return $result;

        $readback = $this->findReadback($result);
        if ($readback === null) return [...$result, 'status' => 'ORGANIZATION_BLOCKED', 'code' => 'CANONICAL_READBACK_FAILED', 'blockers' => [['code' => 'CANONICAL_READBACK_FAILED']]];
        $entity = $this->authority->findByCanonicalId($readback['uuid']);
        if (!$entity instanceof AuthorityEntity) return [...$result, 'status' => 'ORGANIZATION_BLOCKED', 'code' => 'CANONICAL_READBACK_UNAVAILABLE', 'blockers' => [['code' => 'CANONICAL_READBACK_UNAVAILABLE']]];
        $profile = $this->profiles->resolveProfile($entity);
        if (!$entity->active() || $entity->entityType !== 'classification' || (($entity->payload['family'] ?? null) !== 'clock_type') || !$profile->resolved() || $profile->profileKey !== 'clock_type') {
            return [...$result, 'status' => 'ORGANIZATION_BLOCKED', 'code' => 'CLOCK_TYPE_CANONICAL_READBACK_INVALID', 'blockers' => [['code' => 'CLOCK_TYPE_CANONICAL_READBACK_INVALID', 'canonical_uuid' => $entity->canonicalId]]];
        }
        $duplicates = $this->duplicates($entity);
        if ($duplicates !== []) return [...$result, 'status' => 'ORGANIZATION_BLOCKED', 'code' => 'CLOCK_TYPE_DUPLICATE_CANONICAL', 'blockers' => [['code' => 'CLOCK_TYPE_DUPLICATE_CANONICAL']], 'duplicate_verification' => ['duplicates' => $duplicates]];
        return [...$result, 'canonical_readback' => ['uuid' => $entity->canonicalId, 'revision' => $entity->revision, 'name' => $entity->canonicalName, 'stable_key' => $entity->stableKey, 'family' => 'clock_type', 'profile' => $profile->profileKey], 'duplicate_verification' => ['duplicates' => []]];
    }

    /** @return array{uuid:string}|null */
    private function findReadback(array $result): ?array
    {
        foreach ((array) ($result['apply_results'] ?? []) as $item) {
            $readback = is_array($item) && is_array($item['canonical_readback'] ?? null) ? $item['canonical_readback'] : [];
            $snapshot = is_array($readback['snapshot'] ?? null) ? $readback['snapshot'] : [];
            $uuid = trim((string) ($readback['canonical_id'] ?? $snapshot['canonicalId'] ?? ''));
            if ($uuid !== '') return ['uuid' => $uuid];
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function duplicates(AuthorityEntity $entity): array
    {
        $rows = [];
        foreach ($this->authority->listByType('classification') as $candidate) {
            if (!$candidate instanceof AuthorityEntity || $candidate->canonicalId === $entity->canonicalId || !$candidate->active()) continue;
            if (($candidate->payload['family'] ?? null) !== 'clock_type') continue;
            if ($candidate->stableKey === $entity->stableKey || CommandCanonicalizer::canonicalize($candidate->canonicalName) === CommandCanonicalizer::canonicalize($entity->canonicalName)) $rows[] = ['canonical_uuid' => $candidate->canonicalId, 'stable_key' => $candidate->stableKey, 'name' => $candidate->canonicalName];
        }
        return $rows;
    }
}
