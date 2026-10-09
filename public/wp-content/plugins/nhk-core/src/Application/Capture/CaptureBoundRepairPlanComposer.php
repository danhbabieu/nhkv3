<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Governance\GovernedOperationPolicyRegistry;
use NHK\Core\Application\Knowledge\{CanonicalDependencyValidator, KnowledgeRepairPreviewService};
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Composes an exact, generic repair plan for an existing Capture.
 *
 * This class is planning-only. It never writes an owner and never issues a
 * scope. Every resulting node is still passed through the existing Capture
 * scope signer and the ordinary Governance lifecycle.
 */
final class CaptureBoundRepairPlanComposer
{
    /** @var list<string> */
    private const ENTITY_TYPES = ['knowledge', 'source', 'evidence'];
    /** @var list<string> */
    private const OPERATIONS = ['update', 'retire'];

    public function __construct(
        private KnowledgeRepository $knowledge,
        private ?KnowledgeRepairPreviewService $preview = null,
        private ?CanonicalDependencyValidator $dependencies = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function compose(string $captureId, array $request): array
    {
        if (!UuidCodec::isValid($captureId)) throw new \InvalidArgumentException('CAPTURE_REPAIR_CAPTURE_ID_INVALID');
        $operations = self::operations($request);
        if ($operations === []) throw new \InvalidArgumentException('CAPTURE_REPAIR_OPERATIONS_REQUIRED');
        if (count($operations) > 50) throw new \InvalidArgumentException('CAPTURE_REPAIR_OPERATION_LIMIT_EXCEEDED');

        $plans = [];
        $seen = [];
        foreach ($operations as $index => $operation) {
            if (!is_array($operation)) throw new \InvalidArgumentException('CAPTURE_REPAIR_OPERATION_INVALID');
            $plan = $this->composeOperation($captureId, $operation, $index);
            $identity = (string) ($plan['entity_type'] ?? '') . ':' . (string) ($plan['operation'] ?? '') . ':' . (string) ($plan['target_uuid'] ?? $plan['subject_id'] ?? '');
            if (isset($seen[$identity])) throw new \InvalidArgumentException('CAPTURE_REPAIR_DUPLICATE_OWNER');
            $seen[$identity] = true;
            $plans[] = $plan;
        }
        return $plans;
    }

    /** @return list<array<string,mixed>> */
    public static function operations(array $request): array
    {
        if (isset($request['operations'])) return array_values((array) $request['operations']);
        $operations = [];
        foreach (['knowledge', 'knowledge_repairs', 'source_evidence', 'source_evidence_reconciliation', 'provenance'] as $bucket) {
            foreach ((array) ($request[$bucket] ?? []) as $operation) $operations[] = $operation;
        }
        return $operations;
    }

    /** @return array<string,mixed> */
    private function composeOperation(string $captureId, array $input, int $index): array
    {
        $entityType = strtolower(trim((string) ($input['entity_type'] ?? $input['owner_type'] ?? '')));
        if (!in_array($entityType, self::ENTITY_TYPES, true)) throw new \InvalidArgumentException('CAPTURE_REPAIR_ENTITY_TYPE_INVALID');
        $operation = strtolower(trim((string) ($input['operation'] ?? '')));
        if (!in_array($operation, self::OPERATIONS, true)) throw new \InvalidArgumentException('CAPTURE_REPAIR_OPERATION_UNREGISTERED');

        $ownerId = trim((string) ($input['canonical_owner_id'] ?? $input['canonical_id'] ?? $input['target_uuid'] ?? $input['canonical_knowledge_uuid'] ?? ''));
        if (!UuidCodec::isValid($ownerId)) throw new \InvalidArgumentException('CAPTURE_REPAIR_CANONICAL_OWNER_ID_REQUIRED');
        $owner = $this->owner($entityType, $ownerId);
        if ($owner === null) throw new \InvalidArgumentException('CAPTURE_REPAIR_CANONICAL_OWNER_NOT_FOUND');
        $expectedRevision = (int) ($input['expected_revision'] ?? $input['canonical_owner_revision'] ?? 0);
        $currentRevision = (int) ($owner->revision ?? 0);
        if ($expectedRevision < 1 || $expectedRevision !== $currentRevision) throw new \InvalidArgumentException('CAPTURE_REPAIR_CANONICAL_OWNER_REVISION_CHANGED');

        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $payload['canonical_id'] = $ownerId;
        $payload['canonical_owner_id'] = $ownerId;
        $payload['canonical_owner_revision'] = $expectedRevision;
        $payload['repair'] = is_array($payload['repair'] ?? null) ? $payload['repair'] : [];
        $payload['repair'] = array_merge($payload['repair'], [
            'target_uuid' => $ownerId,
            'expected_revision' => $expectedRevision,
            'reason' => trim((string) ($input['reason'] ?? $payload['repair']['reason'] ?? '')),
            'provenance' => is_array($input['provenance'] ?? null) ? $input['provenance'] : (is_array($payload['repair']['provenance'] ?? null) ? $payload['repair']['provenance'] : []),
        ]);
        if ($payload['repair']['reason'] === '' || $payload['repair']['provenance'] === []) throw new \InvalidArgumentException('CAPTURE_REPAIR_PROVENANCE_REQUIRED');

        $dependencyIds = array_values(array_unique(array_filter(array_map('strval', (array) ($input['dependency_ids'] ?? $payload['dependency_ids'] ?? [])), static fn (string $id): bool => UuidCodec::isValid($id))));
        $dependencyRevisions = $this->dependencyRevisions($input['dependency_revisions'] ?? $payload['dependency_revisions'] ?? []);
        if ($entityType === 'knowledge') {
            $plan = $this->composeKnowledge($captureId, $input, $owner, $operation, $ownerId, $expectedRevision, $payload, $dependencyIds, $dependencyRevisions);
        } else {
            $plan = $this->composeSourceOrEvidence($captureId, $input, $owner, $entityType, $operation, $ownerId, $expectedRevision, $payload, $dependencyIds, $dependencyRevisions);
        }
        $plan['dependency_ids'] = array_values(array_unique(array_map('strval', (array) ($plan['dependency_ids'] ?? $dependencyIds))));
        $plan['dependency_revisions'] = $this->dependencyRevisions($plan['dependency_revisions'] ?? $dependencyRevisions);
        $plan['payload']['dependency_ids'] = $plan['dependency_ids'];
        $plan['payload']['dependency_revisions'] = $plan['dependency_revisions'];
        $plan['payload']['capture_id'] = $captureId;
        $plan['repair_plan_index'] = $index;
        $plan['repair_plan_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize([
            'capture_id' => $captureId,
            'entity_type' => $plan['entity_type'],
            'operation' => $plan['operation'],
            'owner_id' => $ownerId,
            'owner_revision' => $expectedRevision,
            'dependency_ids' => $plan['dependency_ids'],
            'dependency_revisions' => $plan['dependency_revisions'],
            'payload' => $plan['payload'],
        ]));
        return $plan;
    }

    /** @return array<string,mixed> */
    private function composeKnowledge(string $captureId, array $input, KnowledgeClaim|Source|Evidence $owner, string $operation, string $ownerId, int $revision, array $payload, array $dependencyIds, array $dependencyRevisions): array
    {
        $repairInput = array_merge($input, [
            'canonical_knowledge_uuid' => $ownerId,
            'expected_revision' => $revision,
            'operation' => $operation,
            'reason' => $payload['repair']['reason'],
            'provenance' => $payload['repair']['provenance'],
            'cleanup_class' => (string) ($input['cleanup_class'] ?? $payload['repair']['cleanup_class'] ?? 'PROCESS_CONTAMINATION'),
        ]);
        $intent = KnowledgeRepairIntent::fromArray($repairInput);
        $payload['text'] = $intent->text ?? $owner->claimText;
        $payload['claim_type'] = $intent->claimType ?? $owner->claimType;
        $payload['provenance'] = $intent->provenance;
        if ($operation === 'retire') {
            if ($this->preview === null) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_PREVIEW_REQUIRED');
            $preview = $this->preview->preview($repairInput);
            $identity = is_array($preview['identity'] ?? null) ? $preview['identity'] : [];
            $payload['repair']['identity_binding'] = [
                'policy_version' => (string) ($identity['policy_version'] ?? ''),
                'identity_fingerprint' => (string) ($identity['fingerprint'] ?? ''),
                'dependency_fingerprint' => (string) ($preview['dependency_fingerprint'] ?? ''),
                'classification' => strtoupper((string) ($identity['status'] ?? 'UNRESOLVED')),
            ];
            // The first preview is the read-only inventory pass. The second
            // pass validates the server-derived binding; a caller cannot
            // self-assert identity or dependency readiness.
            $validatedInput = array_merge($repairInput, ['identity_binding' => $payload['repair']['identity_binding']]);
            $validated = $this->preview->preview($validatedInput);
            $payload['repair']['manual_review_required'] = ($validated['status'] ?? '') === 'REVIEW_REQUIRED';
            $payload['repair']['dependency_inventory'] = ['graph' => $validated['graph_dependencies'] ?? [], 'evidence' => $validated['evidence_dependencies'] ?? [], 'blockers' => $validated['blockers'] ?? []];
            foreach ((array) ($preview['graph_dependencies'] ?? []) as $dependency) {
                if (!is_array($dependency)) continue;
                $id = trim((string) ($dependency['uuid'] ?? ''));
                if (UuidCodec::isValid($id)) { $dependencyIds[] = $id; $dependencyRevisions[$id] = (int) ($dependency['revision'] ?? 0); }
            }
            foreach ((array) ($preview['evidence_dependencies'] ?? []) as $dependency) {
                if (!is_array($dependency)) continue;
                $id = trim((string) ($dependency['canonical_id'] ?? ''));
                if (UuidCodec::isValid($id)) { $dependencyIds[] = $id; $dependencyRevisions[$id] = (int) ($dependency['revision'] ?? 0); }
            }
        }
        $payload['repair']['cleanup_class'] = $intent->cleanupClass;
        return $this->plan('knowledge', $operation, $ownerId, $ownerId, $revision, $payload, $dependencyIds, $dependencyRevisions, $captureId);
    }

    /** @return array<string,mixed> */
    private function composeSourceOrEvidence(string $captureId, array $input, Source|Evidence $owner, string $entityType, string $operation, string $ownerId, int $revision, array $payload, array $dependencyIds, array $dependencyRevisions): array
    {
        if ($entityType === 'evidence') {
            $claimId = trim((string) ($payload['claim_id'] ?? $payload['claim_uuid'] ?? $owner->claimId));
            $sourceId = trim((string) ($payload['source_id'] ?? $payload['source_uuid'] ?? $owner->sourceId));
            if (!UuidCodec::isValid($claimId) || !UuidCodec::isValid($sourceId) || $this->dependencies === null) throw new \InvalidArgumentException('CAPTURE_REPAIR_EVIDENCE_DEPENDENCY_REQUIRED');
            $claim = $this->dependencies->claim($claimId);
            $source = $this->dependencies->source($sourceId);
            $payload['claim_id'] = $claimId;
            $payload['source_id'] = $sourceId;
            $payload['claim_revision'] = (int) ($payload['claim_revision'] ?? $claim->revision);
            $payload['source_revision'] = (int) ($payload['source_revision'] ?? $source->revision);
            $dependencyIds = array_values(array_unique(array_merge($dependencyIds, [$claimId, $sourceId])));
            $dependencyRevisions[$claimId] = (int) $payload['claim_revision'];
            $dependencyRevisions[$sourceId] = (int) $payload['source_revision'];
            $payload['dependency_revisions'] = $dependencyRevisions;
        }
        $payload['repair']['reconciliation'] = true;
        return $this->plan($entityType, $operation, $ownerId, $ownerId, $revision, $payload, $dependencyIds, $dependencyRevisions, $captureId);
    }

    /** @return array<string,mixed> */
    private function plan(string $entityType, string $operation, string $subjectId, string $targetId, int $revision, array $payload, array $dependencyIds, array $dependencyRevisions, ?string $captureId): array
    {
        $identity = $captureId !== null ? 'capture:' . $captureId . ':repair:' . hash('sha256', CommandCanonicalizer::canonicalize([$entityType, $operation, $targetId, $revision, $payload, $dependencyIds, $dependencyRevisions])) : '';
        return [
            'entity_type' => $entityType,
            'operation' => $operation,
            'subject_id' => $subjectId,
            'target_uuid' => $targetId,
            'expected_revision' => $revision,
            'idempotency_key' => $identity,
            'dependency_ids' => array_values(array_unique(array_map('strval', $dependencyIds))),
            'dependency_revisions' => $this->dependencyRevisions($dependencyRevisions),
            'payload' => $payload,
            'repair' => true,
        ];
    }

    private function owner(string $type, string $id): KnowledgeClaim|Source|Evidence|null
    {
        return match ($type) {
            'knowledge' => $this->knowledge->findByCanonicalId($id),
            'source' => $this->dependencies?->source($id),
            'evidence' => $this->dependencies?->evidence($id),
            default => null,
        };
    }

    /** @return array<string,int> */
    private function dependencyRevisions(mixed $value): array
    {
        $revisions = [];
        foreach (is_array($value) ? $value : [] as $id => $revision) {
            $id = trim((string) $id);
            $revision = (int) $revision;
            if (UuidCodec::isValid($id) && $revision > 0) $revisions[$id] = $revision;
        }
        ksort($revisions, SORT_STRING);
        return $revisions;
    }
}
