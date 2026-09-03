<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, ControlledApplyService, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Knowledge\{KnowledgeEnrichmentProposalFactory, KnowledgeService};
use NHK\Core\Contracts\Governance\{ApplyAttemptRepository, ApplyExecutionHook, DependencyRepository, EligibilityReader, GovernanceAuditSink, ProposalRepository};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{ApplyAttempt, DependencyGraph, Proposal, ProposalState};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgeEnrichmentCandidate, KnowledgeFacetProfile, Source};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernedLivingKnowledgeE2ETest extends TestCase
{
    public function test_candidate_to_canonical_readback_covers_new_claim_and_all_evidence_relations(): void
    {
        foreach (['new_claim' => null, 'add_evidence' => 'supports', 'qualify' => 'qualifies', 'contradict' => 'contradicts'] as $classification => $relation) {
            $fixture = $this->fixture();
            $subject = UuidCodec::newV7();
            $provenance = [];
            if ($relation !== null) {
                $claim = $fixture['knowledge']->createClaim('e2e:claim:' . $relation, 'Claim for ' . $relation . '.', 'fact');
                $source = $fixture['knowledge']->createSource('e2e:source:' . $relation, 'Source for ' . $relation, 'website', null, ['visibility' => 'PUBLIC']);
                $provenance = ['claim_id' => $claim->canonicalId, 'source_id' => $source->canonicalId, 'relation' => $relation];
            }
            $candidate = new KnowledgeEnrichmentCandidate($classification, $subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Observed ' . ($relation ?? 'new claim') . '.', $provenance);
            $arguments = $fixture['factory']->arguments($candidate, 'e2e-run-' . $classification);
            $proposal = $fixture['governance']->create(new Proposal(
                UuidCodec::newV7(), $arguments['subject_id'], $arguments['operation'], $arguments['payload'],
                $arguments['content_fingerprint'], $arguments['expected_revision'], $arguments['dependency_fingerprint'],
                ProposalState::DRAFT, 'owner', null, null, $arguments['idempotency_key'], 1, null, null, null, $arguments['entity_type']
            ));

            $submitted = $fixture['governance']->submit($proposal->id);
            $review = $fixture['governance']->review($submitted->id);
            $approved = $fixture['governance']->approve($review->id, $review->contentFingerprint, $review->dependencyFingerprint, 'reviewer');
            self::assertTrue($fixture['eligibility']->check($approved->id)->ready);

            $result = $fixture['apply']->apply($approved->id);
            self::assertFalse($result['idempotent']);
            self::assertNotNull($result['result_entity_uuid']);
            self::assertSame(ProposalState::APPLIED, $fixture['proposals']->find($approved->id)?->state);
            self::assertNotNull($fixture['readBack']($result['result_entity_uuid'], $arguments['entity_type']));

            $replay = $fixture['apply']->apply($approved->id);
            self::assertTrue($replay['idempotent']);
            self::assertSame($result['result_entity_uuid'], $replay['result_entity_uuid']);
            self::assertCount(1, $fixture['attempts']->findByProposal($approved->id));
            self::assertContains('ApplySucceeded', $fixture['audit']->events);
        }
    }

    public function test_changed_dependency_after_approval_is_stale_and_cannot_apply(): void
    {
        $fixture = $this->fixture();
        $subject = UuidCodec::newV7();
        $claim = $fixture['knowledge']->createClaim('e2e:stale:claim', 'Stale dependency claim.', 'fact');
        $source = $fixture['knowledge']->createSource('e2e:stale:source', 'Stale dependency source', 'website');
        $candidate = new KnowledgeEnrichmentCandidate('add_evidence', $subject, new KnowledgeFacetProfile('recognition', 'variant'), 'Stale source observation.', ['claim_id' => $claim->canonicalId, 'source_id' => $source->canonicalId, 'relation' => 'supports']);
        $arguments = $fixture['factory']->arguments($candidate, 'e2e-stale');
        $proposal = $fixture['governance']->create($this->proposalFrom($arguments));
        $approved = $fixture['governance']->approve($fixture['governance']->submit($proposal->id)->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'reviewer');
        $fixture['proposals']->changeDependency($approved->id, hash('sha256', 'changed-dependency'));

        $eligibility = $fixture['eligibility']->check($approved->id);
        self::assertFalse($eligibility->ready);
        self::assertContains('APPROVAL_BINDING_MISMATCH', $eligibility->reasons);
    }

    public function test_failure_atomicity_rolls_back_claim_but_keeps_failed_attempt_and_approved_proposal(): void
    {
        $fixture = $this->fixture(new class implements ApplyExecutionHook {
            public function afterAttemptStarted(): void {}
            public function afterAuthorityMutation(): void { throw new \RuntimeException('INJECTED_E2E_FAILURE'); }
            public function beforeProposalApplied(): void {}
            public function beforeCommit(): void {}
        });
        $candidate = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('recognition', 'variant'), 'Atomicity claim.');
        $arguments = $fixture['factory']->arguments($candidate, 'e2e-atomicity');
        $proposal = $fixture['governance']->create($this->proposalFrom($arguments));
        $approved = $fixture['governance']->approve($fixture['governance']->submit($proposal->id)->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'reviewer');

        try { $fixture['apply']->apply($approved->id); self::fail('Expected controlled failure.'); }
        catch (\RuntimeException $error) { self::assertSame('INJECTED_E2E_FAILURE', $error->getMessage()); }

        self::assertSame(ProposalState::APPROVED, $fixture['proposals']->find($approved->id)?->state);
        self::assertNull($fixture['knowledgeClaims']->findByStableKey($arguments['payload']['stable_key']));
        self::assertSame('failed', $fixture['attempts']->findByProposal($approved->id)[0]->state);
        self::assertContains('ApplyFailed', $fixture['audit']->events);
    }

    /** @param array<string,mixed> $arguments */
    private function proposalFrom(array $arguments): Proposal
    {
        return new Proposal(UuidCodec::newV7(), $arguments['subject_id'], $arguments['operation'], $arguments['payload'], $arguments['content_fingerprint'], $arguments['expected_revision'], $arguments['dependency_fingerprint'], ProposalState::DRAFT, 'owner', null, null, $arguments['idempotency_key'], 1, null, null, null, $arguments['entity_type']);
    }

    /** @return array<string,mixed> */
    private function fixture(?ApplyExecutionHook $hook = null): array
    {
        $claims = new class implements KnowledgeRepository { public array $items = []; public function findByCanonicalId(string $id): ?KnowledgeClaim { return $this->items[$id] ?? null; } public function findByStableKey(string $key): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { return $this->items[$claim->canonicalId] = $claim; } public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { return $this->items[$claim->canonicalId] = new KnowledgeClaim($claim->canonicalId, $claim->stableKey, $claim->claimText, $claim->claimType, $claim->provenance, $claim->active, $revision + 1); } public function list(bool $includeRetired = false): array { return array_values($this->items); } };
        $sources = new class implements SourceRepository { public array $items = []; public function findByCanonicalId(string $id): ?Source { return $this->items[$id] ?? null; } public function findByStableKey(string $key): ?Source { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; } public function create(Source $source): Source { return $this->items[$source->canonicalId] = $source; } public function update(Source $source, int $revision): Source { return $this->items[$source->canonicalId] = new Source($source->canonicalId, $source->stableKey, $source->title, $source->sourceType, $source->locator, $source->metadata, $source->active, $revision + 1); } public function list(bool $includeRetired = false): array { return array_values($this->items); } };
        $evidence = new class implements EvidenceRepository { public array $items = []; public function findByCanonicalId(string $id): ?Evidence { return $this->items[$id] ?? null; } public function create(Evidence $item): Evidence { return $this->items[$item->canonicalId] = $item; } public function update(Evidence $item, int $revision): Evidence { return $this->items[$item->canonicalId] = new Evidence($item->canonicalId, $item->claimId, $item->sourceId, $item->relation, $item->excerpt, $item->locator, $item->active, $revision + 1, $item->metadata); } public function listByClaim(string $claimId, bool $includeRetired = false): array { return array_values(array_filter($this->items, fn (Evidence $item): bool => $item->claimId === $claimId)); } public function listBySource(string $sourceId, bool $includeRetired = false): array { return array_values(array_filter($this->items, fn (Evidence $item): bool => $item->sourceId === $sourceId)); } };
        $knowledge = new KnowledgeService($claims, $sources, $evidence);
        $proposals = new class implements ProposalRepository { public array $items = []; public array $approvals = []; public function create(Proposal $proposal): Proposal { return $this->items[$proposal->id] = $proposal; } public function find(string $id): ?Proposal { return $this->items[$id] ?? null; } public function findByIdempotencyKey(string $key): ?Proposal { foreach ($this->items as $item) if ($item->idempotencyKey === $key) return $item; return null; } public function save(Proposal $proposal): Proposal { return $this->items[$proposal->id] = $proposal; } public function findForUpdate(string $id): ?Proposal { return $this->find($id); } public function recordApproval(Proposal $proposal, string $actor): void { $this->approvals[$proposal->id] = ['proposal_revision' => $proposal->revision, 'fingerprint' => $proposal->bindingFingerprint(), 'actor' => $actor]; } public function latestApproval(string $proposalId): ?array { return $this->approvals[$proposalId] ?? null; } public function changeDependency(string $id, string $dependency): void { $p = $this->items[$id]; $this->items[$id] = new Proposal($p->id, $p->subjectId, $p->operation, $p->payload, $p->contentFingerprint, $p->expectedRevision, $dependency, $p->state, $p->actor, $p->decisionActor, $p->decidedAt, $p->idempotencyKey, $p->revision, $p->submittedAt, $p->appliedAt, $p->targetUuid, $p->entityType, $p->createdAt, $p->updatedAt, $p->cancelledAt, $p->rejectedAt, $p->supersededAt, $p->supersededByProposalId); } };
        $attempts = new class implements ApplyAttemptRepository { public array $items = []; public function nextAttemptNumberLocked(string $proposalId): int { return count($this->findByProposal($proposalId)) + 1; } public function createRunning(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; } public function markSucceeded(string $id, ?string $result): ApplyAttempt { $a = $this->items[$id]; return $this->items[$id] = new ApplyAttempt($a->id, $a->proposalId, $a->number, 'succeeded', $result, null, null, $a->startedAt, gmdate('Y-m-d H:i:s.u')); } public function persistFailed(ApplyAttempt $attempt): ApplyAttempt { return $this->items[$attempt->id] = $attempt; } public function findByProposal(string $proposalId): array { return array_values(array_filter($this->items, fn (ApplyAttempt $item): bool => $item->proposalId === $proposalId)); } public function findSuccessful(string $proposalId): ?ApplyAttempt { foreach ($this->findByProposal($proposalId) as $item) if ($item->state === 'succeeded') return $item; return null; } };
        $audit = new class implements GovernanceAuditSink { public array $events = []; public function record(string $event, Proposal $proposal): void { $this->events[] = ucfirst($event) === 'Applied' ? 'ApplySucceeded' : 'Proposal' . ucfirst($event); } public function recordEvent(string $eventType, string $objectType, string $objectKey, ?int $actorUserId, array $context = []): void { $this->events[] = $eventType; } };
        $transactions = new class($claims, $sources, $evidence, $proposals, $attempts, $audit) implements TransactionManager { private array $objects; public function __construct(...$objects) { $this->objects = $objects; } public function begin(): void {} public function commit(): void {} public function rollback(): void {} public function transactional(callable $callback): mixed { $snapshots = array_map(fn (object $object): mixed => $object instanceof \NHK\Tests\Unit\GovernedLivingKnowledgeE2ETest ? null : (property_exists($object, 'items') ? $object->items : (property_exists($object, 'approvals') ? [$object->items, $object->approvals] : (property_exists($object, 'events') ? $object->events : null))), $this->objects); try { return $callback(); } catch (\Throwable $error) { foreach ($this->objects as $i => $object) { if (property_exists($object, 'items') && is_array($snapshots[$i]) && !isset($snapshots[$i][0])) $object->items = $snapshots[$i]; elseif (property_exists($object, 'approvals') && is_array($snapshots[$i])) { $object->items = $snapshots[$i][0]; $object->approvals = $snapshots[$i][1]; } elseif (property_exists($object, 'events') && is_array($snapshots[$i])) $object->events = $snapshots[$i]; } throw $error; } } public function run(callable $callback): mixed { return $this->transactional($callback); } };
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $executor = new AuthorityProposalExecutor(new AuthorityService(new class implements \NHK\Core\Contracts\Authority\AuthorityRepository { public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Authority\AuthorityEntity { return null; } public function findByStableKey(string $type, string $key): ?\NHK\Core\Domain\Authority\AuthorityEntity { return null; } public function create(\NHK\Core\Domain\Authority\AuthorityEntity $entity): \NHK\Core\Domain\Authority\AuthorityEntity { return $entity; } public function update(\NHK\Core\Domain\Authority\AuthorityEntity $entity, int $revision): \NHK\Core\Domain\Authority\AuthorityEntity { return $entity; } public function rekey(\NHK\Core\Domain\Authority\AuthorityEntity $entity, string $old, string $new, int $revision): \NHK\Core\Domain\Authority\AuthorityEntity { return $entity; } public function listByType(string $type, bool $includeRetired = false): array { return []; } }, $types), knowledge: $knowledge);
        $reader = new class($claims, $sources, $evidence, $proposals) implements EligibilityReader { public function __construct(private object $claims, private object $sources, private object $evidence, private object $proposals) {} public function isApplied(string $dependencyUuid): bool { return $this->proposals->find($dependencyUuid)?->state === ProposalState::APPLIED; } public function targetRevision(string $targetUuid): ?int { return $this->claims->findByCanonicalId($targetUuid)?->revision ?? $this->sources->findByCanonicalId($targetUuid)?->revision ?? $this->evidence->findByCanonicalId($targetUuid)?->revision; } public function targetExists(string $targetUuid): bool { return $this->targetRevision($targetUuid) !== null; } };
        $eligibility = new ProposalEligibilityService($proposals, new DependencyGraph(new class implements DependencyRepository { public function directDependencies(string $proposalId): array { return []; } public function add(string $proposalId, string $dependencyUuid): void {} }), $reader);
        $apply = new ControlledApplyService($proposals, $attempts, $transactions, $executor, $audit, $eligibility, $hook);
        return ['knowledge' => $knowledge, 'knowledgeClaims' => $claims, 'factory' => new KnowledgeEnrichmentProposalFactory(), 'governance' => new GovernanceService($proposals, $audit), 'proposals' => $proposals, 'attempts' => $attempts, 'audit' => $audit, 'eligibility' => $eligibility, 'apply' => $apply, 'readBack' => fn (string $id, string $type): mixed => $type === 'knowledge' ? $claims->findByCanonicalId($id) : $evidence->findByCanonicalId($id)];
    }
}
