<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\RelationProposalReconciliationService;
use NHK\Core\Application\Governance\{GovernanceAutomationPolicyResolver, GovernanceService};
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle, ProposalRepository};
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class RelationProposalReconciliationServiceTest extends TestCase
{
    public function test_stale_relation_is_rebuilt_with_fresh_endpoint_revisions_before_apply(): void
    {
        $proposalId = UuidCodec::newV7();
        $replacementId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $original = new Proposal($proposalId, '1:485', 'relation_create', [
            'source_type' => 'wp_post', 'source_uuid' => '1:485',
            'target_type' => 'classification', 'target_uuid' => $targetId,
            'predicate' => 'about', 'source_revision' => 1, 'target_revision' => 1,
        ], 'original-content', null, 'original-dependency', ProposalState::APPROVED, entityType: 'relation');
        $replacement = new Proposal($replacementId, '1:485', 'relation_create', [
            'source_type' => 'wp_post', 'source_uuid' => '1:485',
            'target_type' => 'classification', 'target_uuid' => $targetId,
            'predicate' => 'about', 'source_revision' => 2, 'target_revision' => 1,
        ], 'replacement-content', null, 'replacement-dependency', ProposalState::DRAFT, entityType: 'relation');

        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new class implements EndpointRevisionReader {
            public function supports(string $endpoint_type): bool { return $endpoint_type === 'wp_post'; }
            public function exists(NodeReference $reference): bool { return true; }
            public function normalize(NodeReference $reference): NodeReference { return $reference; }
            public function revision(NodeReference $reference): ?int { return 2; }
        });
        $endpoints->register('classification', new class($targetId) implements EndpointRevisionReader {
            public function __construct(private string $id) {}
            public function supports(string $endpoint_type): bool { return $endpoint_type === 'classification'; }
            public function exists(NodeReference $reference): bool { return $reference->endpoint_key === $this->id; }
            public function normalize(NodeReference $reference): NodeReference { return $reference; }
            public function revision(NodeReference $reference): ?int { return 1; }
        });

        $lifecycle = $this->createMock(GovernedLifecycle::class);
        $lifecycle->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $args): bool => ($args['idempotency_key'] ?? '') !== $original->idempotencyKey && ($args['payload']['source_revision'] ?? 0) === 2))->willReturn($replacement);
        $lifecycle->expects(self::once())->method('submit')->with($replacementId)->willReturn($replacement->transition(ProposalState::SUBMITTED));
        $lifecycle->expects(self::exactly(2))->method('review')->with($replacementId)->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'content_fingerprint' => 'replacement-content', 'dependency_fingerprint' => 'replacement-dependency'],
            ['state' => 'submitted', 'content_fingerprint' => 'replacement-content', 'dependency_fingerprint' => 'replacement-dependency'],
        );
        $lifecycle->expects(self::once())->method('approve')->with($replacementId, 'replacement-content', 'replacement-dependency', self::anything())->willReturn($replacement->transition(ProposalState::APPROVED));
        $lifecycle->expects(self::once())->method('eligibility')->with($replacementId)->willReturn(['ready' => true, 'reasons' => []]);
        $proposalStore = new class($original, $replacement) implements ProposalRepository {
            public ?Proposal $saved = null;
            public function __construct(private Proposal $original, private Proposal $replacement) {}
            public function create(Proposal $proposal): Proposal { return $proposal; }
            public function find(string $id): ?Proposal { return $id === $this->replacement->id ? $this->replacement : ($id === $this->original->id ? ($this->saved ?? $this->original) : null); }
            public function findByIdempotencyKey(string $key): ?Proposal { return null; }
            public function save(Proposal $proposal): Proposal { $this->saved = $proposal; return $proposal; }
            public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
            public function recordApproval(Proposal $proposal, string $actor): void {}
            public function latestApproval(string $proposalId): ?array { return null; }
            public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
        };
        $governance = new GovernanceService($proposalStore);

        $service = new RelationProposalReconciliationService(
            $lifecycle, $governance, $endpoints,
            static fn (string $id): array => ['canonical_id' => 'edge-1', 'canonical_readback' => ['canonical_id' => 'edge-1', 'active' => true]],
            new GovernanceAutomationPolicyResolver(['relation'], new class implements AutomationPolicyStorage { public function read(): array { return ['relation' => 'AUTO_PUBLISH']; } public function write(array $policies): void {} }),
            static fn (string $capability): bool => true,
            static fn (): string => 'capture-reconciler',
        );

        $result = $service->reconcile($original, ['approval_confirmed' => true]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($replacementId, $result['proposal_id']);
        self::assertSame($proposalId, $result['replaced_proposal_id']);
        self::assertSame('edge-1', $result['canonical_readback']['canonical_id']);
        self::assertSame(ProposalState::SUPERSEDED, $proposalStore->saved?->state);
    }
}
