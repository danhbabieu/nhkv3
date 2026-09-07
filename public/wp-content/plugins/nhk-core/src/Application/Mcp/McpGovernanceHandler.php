<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Governance\GovernedSemanticIngestOrchestrator;
use NHK\Core\Application\Governance\ProposalEligibilityService;
use NHK\Core\Application\Graph\RelationBatchApplyOrchestrator;
use NHK\Core\Application\Graph\RelationRevisionBinder;
use NHK\Core\Domain\Graph\EndpointTypeRegistry;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Contracts\Governance\GovernedLifecycle;

final class McpGovernanceHandler implements GovernedLifecycle
{
    private ?GovernedSemanticIngestOrchestrator $ingestOrchestrator = null;

    public function __construct(
        private GovernanceService $governance,
        private ?ProposalEligibilityService $eligibility = null,
        private ?ControlledApplyService $apply = null,
        private ?GovernanceAutomationPolicyResolver $policyResolver = null,
        private ?EndpointTypeRegistry $endpoints = null,
    ) {}

    /** @return array<string,mixed> */
    public function ingestFromArguments(array $arguments): array
    {
        if ($this->policyResolver === null) return $this->proposal($this->createFromArguments($arguments));
        $this->ingestOrchestrator ??= new GovernedSemanticIngestOrchestrator(
            $this,
            static fn (array $review): bool => true,
            fn (string $id): array => $this->apply($id),
            $this->policyResolver,
        );
        return $this->ingestOrchestrator->run([$arguments])[0];
    }

    public function automationEnabled(): bool
    {
        return $this->policyResolver !== null;
    }

    public function create(Proposal $proposal): Proposal { return $this->governance->create($proposal); }
    public function review(string $id): array
    {
        $proposal = $this->governance->review($id);
        return [
            'proposal_id' => $proposal->id,
            'state' => $proposal->state->value,
            'entity_type' => $proposal->entityType,
            'operation' => $proposal->operation,
            'subject_id' => $proposal->subjectId,
            'target_uuid' => $proposal->targetUuid,
            'payload' => $proposal->payload,
            'expected_revision' => $proposal->expectedRevision,
            'revision' => $proposal->revision,
            'content_fingerprint' => $proposal->contentFingerprint,
            'dependency_fingerprint' => $proposal->dependencyFingerprint,
            'created_at' => $proposal->createdAt,
            'updated_at' => $proposal->updatedAt,
        ];
    }
    public function createFromArguments(array $arguments): Proposal
    {
        $operation = (string) ($arguments['operation'] ?? '');
        $entityType = (string) ($arguments['entity_type'] ?? '');
        $subjectId = (string) ($arguments['subject_id'] ?? '');
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [];
        if ($operation === 'relation_create') {
            if ($this->endpoints === null) throw new \RuntimeException('Relation endpoint revision resolver is unavailable.');
            $payload = (new RelationRevisionBinder($this->endpoints))->bind($payload);
        }
        if ($operation === 'relation_create' && trim((string) ($payload['source_uuid'] ?? '')) !== '') {
            $subjectId = trim((string) $payload['source_uuid']);
        } elseif ($subjectId === '' && in_array($operation, ['create', 'ingest'], true)) {
            $subjectId = $entityType !== '' ? $entityType : 'relation';
        } elseif ($subjectId === '' && $operation === 'relation_create') {
            $subjectId = trim((string) ($payload['source_key'] ?? '')) ?: 'relation';
        }
        $targetUuid = isset($arguments['target_uuid']) ? trim((string) $arguments['target_uuid']) : null;
        $targetUuid = $targetUuid !== '' ? $targetUuid : null;
        $expectedRevision = $operation === 'relation_create' ? null : (array_key_exists('expected_revision', $arguments) && $arguments['expected_revision'] !== null
            ? max(1, (int) $arguments['expected_revision'])
            : (in_array($operation, ['create', 'ingest'], true) && $targetUuid === null ? null : 1));
        $dependencyIds = is_array($arguments['dependency_ids'] ?? null) ? array_values(array_filter(array_map('strval', $arguments['dependency_ids']))) : [];
        $binding = ['operation' => $operation, 'entity_type' => $entityType, 'subject_id' => $subjectId, 'target_uuid' => $targetUuid, 'expected_revision' => $expectedRevision, 'payload' => $payload, 'dependency_ids' => $dependencyIds];
        $contentFingerprint = trim((string) ($arguments['content_fingerprint'] ?? '')) ?: hash('sha256', CommandCanonicalizer::canonicalize($binding));
        $dependencyFingerprint = trim((string) ($arguments['dependency_fingerprint'] ?? '')) ?: hash('sha256', CommandCanonicalizer::canonicalize($dependencyIds));
        $idempotencyKey = trim((string) ($arguments['idempotency_key'] ?? '')) ?: 'mcp-' . hash('sha256', CommandCanonicalizer::canonicalize($binding));
        return $this->create(new Proposal(
            UuidCodec::newV7(),
            $subjectId,
            $operation,
            $payload,
            $contentFingerprint,
            $expectedRevision,
            $dependencyFingerprint,
            actor: function_exists('get_current_user_id') ? (string) get_current_user_id() : '0',
            idempotencyKey: $idempotencyKey,
            targetUuid: $targetUuid,
            entityType: $entityType,
        ));
    }
    public function submit(string $id): Proposal { return $this->governance->submit($id); }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->governance->approve($id, $contentFingerprint, $dependencyFingerprint, $actor); }
    public function reject(string $id, string $actor): Proposal { return $this->governance->reject($id, $actor); }
    public function eligibility(string $id): array
    {
        if (!$this->eligibility) throw new \RuntimeException('Eligibility service is not configured.');
        $result = $this->eligibility->check($id);
        return ['proposal_id' => $id, 'ready' => $result->ready, 'reasons' => $result->reasons];
    }
    public function apply(string $id): array
    {
        if (!$this->apply) throw new \RuntimeException('Controlled Apply service is not configured.');
        return $this->apply->apply($id);
    }

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    public function relationBatchApply(array $candidates, bool $approvalConfirmed): array
    {
        $actor = function_exists('get_current_user_id') ? (string) get_current_user_id() : '0';
        return (new RelationBatchApplyOrchestrator(
            $this,
            fn (string $id): array => $this->apply($id),
            static fn (array $review): bool => $approvalConfirmed && function_exists('current_user_can') && current_user_can('nhk_approve_proposals'),
            $actor,
        ))->run($candidates);
    }

    /** @return array<string,mixed> */
    private function proposal(Proposal $proposal): array
    {
        return [
            'proposal_id' => $proposal->id,
            'proposal_state' => $proposal->state->value,
            'target_uuid' => $proposal->targetUuid,
            'canonical_id' => $proposal->targetUuid,
            'entity_type' => $proposal->entityType,
            'operation' => $proposal->operation,
            'payload' => $proposal->payload,
            'expected_revision' => $proposal->expectedRevision,
            'revision' => $proposal->revision,
            'idempotency_key' => $proposal->idempotencyKey,
        ];
    }
}
