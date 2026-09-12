<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Contracts\Governance\GovernanceAuthorizer;
use NHK\Core\Domain\Governance\{ApplyAttempt, Proposal, ProposalState};
use NHK\Core\Governance\Exception\{ProposalBindingConflict, ProposalIdempotencyConflict};
use NHK\Core\Governance\Exception\GovernancePermissionDenied;
use NHK\Core\Domain\Governance\DependencyGraph;
use NHK\Core\Governance\Exception\DependencyCycle;
use NHK\Tests\Support\{InMemoryDependencyRepository, InMemoryProposalRepository};
use NHK\Core\Infrastructure\Governance\WpdbProposalRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GovernanceCoreTest extends TestCase
{
    public function test_apply_attempt_rejects_invalid_identity_state_and_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApplyAttempt('not-a-uuid', 'not-a-proposal', 0, 'unknown');
    }

    public function test_proposal_rejects_malformed_target_uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Proposal('proposal-1', 'brand', 'rename', ['name' => 'Name'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-1', 1, null, null, 'not-a-uuid', 'brand');
    }

    public function test_approval_binds_content_and_dependency_closure_and_apply_requires_expected_revision(): void
    {
        $service = new GovernanceService($repo = new InMemoryProposalRepository());
        $proposal = $service->create(new Proposal('p1', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 3, 'deps-a', ProposalState::DRAFT, 'author', null, null, 'key-1'));
        self::assertSame($proposal->bindingFingerprint(), $service->create($proposal)->bindingFingerprint());
        $approved = $service->approve('p1', 'content-a', 'deps-a', 'reviewer');
        self::assertSame(ProposalState::APPROVED, $approved->state);
        $this->expectException(ProposalBindingConflict::class);
        $service->markApplied('p1', 4, 'content-a', 'deps-a');
    }

    public function test_changed_content_or_dependencies_cannot_be_approved_or_applied(): void
    {
        $service = new GovernanceService(new InMemoryProposalRepository());
        $service->create(new Proposal('p2', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 1, 'deps-a', ProposalState::DRAFT, null, null, null, 'key-2'));
        $this->expectException(ProposalBindingConflict::class);
        $service->approve('p2', 'content-b', 'deps-a', 'reviewer');
    }

    public function test_approved_proposal_can_be_applied_only_with_the_same_binding(): void
    {
        $service = new GovernanceService(new InMemoryProposalRepository());
        $service->create(new Proposal('p3', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 1, 'deps-a', ProposalState::DRAFT, null, null, null, 'key-3'));
        $service->approve('p3', 'content-a', 'deps-a', 'reviewer');
        self::assertSame(ProposalState::APPLIED, $service->markApplied('p3', 1, 'content-a', 'deps-a')->state);
    }

    public function test_dependency_closure_rejects_direct_and_transitive_cycles(): void
    {
        $graph = new DependencyGraph($repo = new InMemoryDependencyRepository());
        $graph->add('a', 'b'); $graph->add('b', 'c');
        self::assertSame(['b', 'c'], $graph->closure('a'));
        $this->expectException(DependencyCycle::class);
        $graph->add('c', 'a');
    }

    public function test_terminal_states_cannot_be_reopened_and_supersede_requires_replacement(): void
    {
        $proposal = new Proposal('p4', 'entity-1', 'rename', ['name' => 'New'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-4');
        $submitted = $proposal->transition(ProposalState::SUBMITTED, 'author');
        $approved = $submitted->transition(ProposalState::APPROVED, 'reviewer');
        $applied = $approved->transition(ProposalState::APPLIED, 'reviewer');

        $this->expectException(\InvalidArgumentException::class);
        $applied->transition(ProposalState::DRAFT);
    }

    public function test_supersede_requires_a_different_replacement(): void
    {
        $proposal = new Proposal('p5', 'entity-1', 'rename', ['name' => 'New'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-5');

        $this->expectException(\InvalidArgumentException::class);
        $proposal->transition(ProposalState::SUPERSEDED, 'reviewer', null, 'p5');
    }

    public function test_authorizer_denies_unauthorized_proposal_creation(): void
    {
        $authorizer = new class implements GovernanceAuthorizer {
            public function require(string $capability): void
            {
                throw new GovernancePermissionDenied($capability);
            }
        };
        $service = new GovernanceService(new InMemoryProposalRepository(), null, null, $authorizer);

        $this->expectException(GovernancePermissionDenied::class);
        $service->create(new Proposal('p6', 'entity-1', 'rename', ['name' => 'New'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-6'));
    }

    public function test_mcp_empty_optional_target_uuid_is_normalized_to_null(): void
    {
        $handler = new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository()));
        $proposal = $handler->createFromArguments(['operation' => 'create', 'entity_type' => 'brand', 'target_uuid' => '', 'payload' => ['stable_key' => 'brand-empty-target', 'name' => 'Brand']]);
        self::assertNull($proposal->targetUuid);
    }

    public function test_persisted_knowledge_relation_hydration_preserves_source_uuid_as_subject_id(): void
    {
        $sourceUuid = UuidCodec::newV7();
        $repository = new WpdbProposalRepository();
        $hydrate = new \ReflectionMethod($repository, 'hydrate');
        $hydrate->setAccessible(true);

        $proposal = $hydrate->invoke($repository, [
            'id' => 0,
            'proposal_uuid' => UuidCodec::toBinary(UuidCodec::newV7()),
            'entity_type' => 'knowledge',
            'operation' => 'relation_create',
            'target_uuid' => '',
            'expected_revision' => null,
            'command_json' => json_encode(['source_uuid' => $sourceUuid, 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about'], JSON_THROW_ON_ERROR),
            'fingerprint' => hash('sha256', 'content', true),
            'dependency_fingerprint' => hash('sha256', 'dependency', true),
            'state' => 1,
            'revision' => 1,
            'created_by' => 0,
            'idempotency_key' => 'relation-hydration',
            'created_at' => null,
            'updated_at' => null,
            'submitted_at' => null,
            'applied_at' => null,
            'cancelled_at' => null,
            'rejected_at' => null,
            'superseded_at' => null,
            'superseded_by_proposal_id' => null,
        ]);

        self::assertSame($sourceUuid, $proposal?->subjectId);
    }

    public function test_persisted_video_hydration_preserves_stored_canonical_subject_uuid(): void
    {
        $videoUuid = UuidCodec::newV7();
        $repository = new WpdbProposalRepository();
        $hydrate = new \ReflectionMethod($repository, 'hydrate');
        $hydrate->setAccessible(true);

        $proposal = $hydrate->invoke($repository, [
            'id' => 0,
            'proposal_uuid' => UuidCodec::toBinary(UuidCodec::newV7()),
            'subject_id' => $videoUuid,
            'entity_type' => 'video',
            'operation' => 'ingest',
            'target_uuid' => '',
            'expected_revision' => null,
            'command_json' => json_encode(['canonical_id' => $videoUuid, 'metadata' => []], JSON_THROW_ON_ERROR),
            'fingerprint' => hash('sha256', 'content', true),
            'dependency_fingerprint' => hash('sha256', 'dependency', true),
            'state' => 1,
            'revision' => 1,
            'created_by' => 0,
            'idempotency_key' => 'video-hydration',
            'created_at' => null,
            'updated_at' => null,
            'submitted_at' => null,
            'applied_at' => null,
            'cancelled_at' => null,
            'rejected_at' => null,
            'superseded_at' => null,
            'superseded_by_proposal_id' => null,
        ]);

        self::assertSame($videoUuid, $proposal?->subjectId);
    }

    public function test_video_uuid_bound_proposal_rejects_entity_type_literal_subject_before_persistence(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $videoUuid = UuidCodec::newV7();

        $this->expectExceptionMessage('PROPOSAL_SUBJECT_BINDING_INVALID');
        $handler->createFromArguments([
            'operation' => 'ingest',
            'entity_type' => 'video',
            'subject_id' => 'video',
            'payload' => ['canonical_id' => $videoUuid],
            'idempotency_key' => 'invalid-video-subject',
        ]);
    }

    public function test_component_uuid_bound_proposal_rejects_entity_type_literal_subject_before_persistence(): void
    {
        $repository = new InMemoryProposalRepository();
        $handler = new McpGovernanceHandler(new GovernanceService($repository));
        $sourceUuid = UuidCodec::newV7();

        $this->expectExceptionMessage('PROPOSAL_SUBJECT_BINDING_INVALID');
        $handler->createFromArguments([
            'operation' => 'merge',
            'entity_type' => 'component',
            'subject_id' => 'component',
            'target_uuid' => UuidCodec::newV7(),
            'payload' => ['source_uuid' => $sourceUuid, 'source_revision' => 1, 'target_revision' => 1],
            'idempotency_key' => 'invalid-component-subject',
        ]);
    }

    public function test_invalid_approved_video_binding_is_not_eligible_as_subject_unresolved(): void
    {
        $repository = new InMemoryProposalRepository();
        $proposal = new Proposal(UuidCodec::newV7(), 'video', 'ingest', ['canonical_id' => UuidCodec::newV7()], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'invalid-approved-video', entityType: 'video');
        $repository->create($proposal);
        $eligibility = new \NHK\Core\Application\Governance\ProposalEligibilityService(
            $repository,
            new DependencyGraph(new InMemoryDependencyRepository()),
            new class implements \NHK\Core\Contracts\Governance\EligibilityReader {
                public function isApplied(string $dependencyUuid): bool { return true; }
                public function targetRevision(string $targetUuid): ?int { return 1; }
                public function targetExists(string $targetUuid): bool { return true; }
            },
        );

        self::assertSame(['PROPOSAL_SUBJECT_BINDING_INVALID'], $eligibility->check($proposal->id)->reasons);
    }

    public function test_superseded_legacy_idempotency_key_replays_the_governed_replacement(): void
    {
        $repository = new InMemoryProposalRepository();
        $old = new Proposal(UuidCodec::newV7(), 'video', 'ingest', ['canonical_id' => UuidCodec::newV7()], 'old-content', null, 'old-dependency', ProposalState::APPROVED, idempotencyKey: 'legacy-video-key', entityType: 'video');
        $replacementId = UuidCodec::newV7();
        $canonicalId = UuidCodec::newV7();
        $replacement = new Proposal($replacementId, $canonicalId, 'ingest', ['canonical_id' => $canonicalId], 'new-content', null, 'new-dependency', ProposalState::APPROVED, idempotencyKey: 'video-repair:' . $old->id, entityType: 'video');
        $repository->create($old);
        $repository->create($replacement);
        $repository->save($old->transition(ProposalState::SUPERSEDED, 'repairer', null, $replacementId));

        self::assertSame($replacementId, $repository->findByIdempotencyKey('legacy-video-key')?->id);
        self::assertSame('video', $repository->find($old->id)?->subjectId);
    }

    public function test_mcp_relation_requires_revision_aware_endpoint_registry(): void
    {
        $sourceUuid = UuidCodec::newV7();
        $handler = new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository()));
        $this->expectExceptionMessage('Relation endpoint revision resolver is unavailable.');
        $handler->createFromArguments([
            'operation' => 'relation_create',
            'entity_type' => 'knowledge',
            'subject_id' => 'knowledge',
            'payload' => ['source_type' => 'knowledge', 'source_uuid' => $sourceUuid, 'target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about'],
            'idempotency_key' => 'relation-source-authority',
        ]);

    }

    public function test_review_returns_binding_fingerprints_after_submit(): void
    {
        $handler = new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository()));
        $created = $handler->createFromArguments([
            'operation' => 'create', 'entity_type' => 'brand',
            'payload' => ['stable_key' => 'nhk:brand:review'],
            'content_fingerprint' => 'content-review',
            'dependency_fingerprint' => 'dependency-review',
            'idempotency_key' => 'review-key',
        ]);
        $handler->submit($created->id);

        $review = $handler->review($created->id);

        self::assertSame($created->id, $review['proposal_id']);
        self::assertSame('content-review', $review['content_fingerprint']);
        self::assertSame('dependency-review', $review['dependency_fingerprint']);
        self::assertSame('submitted', $review['state']);
    }

    #[RunInSeparateProcess]
    public function test_relation_create_does_not_return_an_unreadable_in_memory_proposal(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        if (!function_exists('wp_json_encode')) {
            eval('function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }');
        }
        $sourceUuid = UuidCodec::newV7();
        $targetUuid = UuidCodec::newV7();
        $repository = new WpdbProposalRepository(new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public string $last_query = '';
            public int $rows_affected = 0;
            public int $insert_id = 0;

            public function prepare(string $query, mixed ...$arguments): string
            {
                return $query;
            }

            public function get_row(string $query, mixed $output): ?array
            {
                return null;
            }

            public function query(string $query): int
            {
                return 1;
            }
        });
        $service = new GovernanceService($repository);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PROPOSAL_READBACK_FAILED: table=wp_nhk_proposals; insert_result=1; affected_rows=0; insert_id=0; canonical_row_present=0');

        $service->create(new Proposal(
            UuidCodec::newV7(),
            $sourceUuid,
            'relation_create',
            [
                'source_type' => 'knowledge',
                'source_uuid' => $sourceUuid,
                'target_type' => 'model',
                'target_uuid' => $targetUuid,
                'predicate' => 'about',
            ],
            'relation-content',
            null,
            'relation-dependency',
            idempotencyKey: 'relation-immediate-readback',
            targetUuid: $targetUuid,
            entityType: 'knowledge',
        ));
    }

    #[RunInSeparateProcess]
    public function test_stale_idempotency_binding_fails_fast_instead_of_being_treated_as_missing(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        $row = [
            'id' => '501',
            'proposal_uuid' => UuidCodec::toBinary(UuidCodec::newV7()),
            'idempotency_key' => 'stale-idempotency-key',
            'operation' => 'relation_create',
            'entity_type' => 'relation',
            'target_uuid' => '',
            'expected_revision' => null,
            'command_json' => '{not-canonical-json}',
            'fingerprint' => str_repeat('a', 32),
            'dependency_fingerprint' => str_repeat('b', 32),
            'state' => '1',
            'revision' => '1',
            'created_by' => '1',
            'created_at' => '2026-09-08 01:23:52.000000',
            'updated_at' => '2026-09-08 01:23:52.000000',
        ];
        $repository = new WpdbProposalRepository(new class($row) {
            public string $prefix = 'wp_';
            public string $last_error = '';

            public function __construct(private array $row) {}
            public function prepare(string $query, mixed ...$arguments): string { return $query; }
            public function get_row(string $query, mixed $output): ?array
            {
                return str_contains($query, 'WHERE idempotency_key=') ? $this->row : null;
            }
            public function get_var(string $query): ?string { return null; }
        });

        $service = new GovernanceService($repository);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IDEMPOTENCY_STALE_BINDING');
        $service->create(new Proposal(
            UuidCodec::newV7(),
            'source',
            'relation_create',
            ['source_uuid' => UuidCodec::newV7(), 'target_uuid' => UuidCodec::newV7(), 'predicate' => 'about'],
            'stale-content',
            null,
            'stale-dependency',
            idempotencyKey: 'stale-idempotency-key',
            entityType: 'relation',
        ));
    }

    public function test_relation_create_is_immediately_readable_with_subject_target_and_idempotency_binding(): void
    {
        $sourceUuid = UuidCodec::newV7();
        $targetUuid = UuidCodec::newV7();
        $service = new GovernanceService(new InMemoryProposalRepository());
        $created = $service->create(new Proposal(
            UuidCodec::newV7(),
            $sourceUuid,
            'relation_create',
            [
                'source_type' => 'knowledge',
                'source_uuid' => $sourceUuid,
                'target_type' => 'model',
                'target_uuid' => $targetUuid,
                'predicate' => 'about',
            ],
            'relation-content',
            null,
            'relation-dependency',
            idempotencyKey: 'relation-immediate-readback',
            targetUuid: $targetUuid,
            entityType: 'knowledge',
        ));

        $review = $service->review($created->id);

        self::assertSame($created->id, $review->id);
        self::assertSame($sourceUuid, $review->subjectId);
        self::assertSame($targetUuid, $review->targetUuid);
        self::assertSame('relation-immediate-readback', $created->idempotencyKey);
    }

    #[RunInSeparateProcess]
    public function test_hydrates_relation_create_with_database_default_zero_expected_revision(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        if (!function_exists('wp_json_encode')) {
            eval('function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }');
        }
        $proposalUuid = UuidCodec::newV7();
        $sourceUuid = UuidCodec::newV7();
        $targetUuid = UuidCodec::newV7();
        $row = [
            'id' => '498',
            'proposal_uuid' => UuidCodec::toBinary($proposalUuid),
            'idempotency_key' => 'relation-hydration-default-zero',
            'operation' => 'relation_create',
            'entity_type' => 'relation',
            'target_uuid' => UuidCodec::toBinary($targetUuid),
            'expected_revision' => '0',
            'command_json' => json_encode([
                'predicate' => 'about',
                'source_uuid' => $sourceUuid,
                'source_type' => 'knowledge',
                'target_uuid' => $targetUuid,
                'target_type' => 'classification',
                'source_revision' => 1,
                'target_revision' => 1,
            ], JSON_THROW_ON_ERROR),
            'fingerprint' => str_repeat('a', 32),
            'dependency_fingerprint' => str_repeat('b', 32),
            'state' => '1',
            'revision' => '1',
            'created_by' => '1',
            'created_at' => '2026-09-08 01:23:52.000000',
            'updated_at' => '2026-09-08 01:23:52.000000',
        ];
        $repository = new WpdbProposalRepository(new class($row) {
            public string $prefix = 'wp_';
            public string $last_error = '';

            public function __construct(private array $row) {}
            public function prepare(string $query, mixed ...$arguments): string { return $query; }
            public function get_row(string $query, mixed $output): ?array
            {
                return str_contains($query, 'SELECT * FROM wp_nhk_proposals') ? $this->row : null;
            }
            public function get_var(string $query): ?string { return null; }
        });

        $proposal = $repository->find($proposalUuid);

        self::assertNotNull($proposal);
        self::assertSame($proposalUuid, $proposal->id);
        self::assertSame($sourceUuid, $proposal->subjectId);
        self::assertSame($targetUuid, $proposal->targetUuid);
        self::assertNull($proposal->expectedRevision);
    }

    public function test_rekey_proposal_idempotency_key_replays_identical_binding_and_rejects_changed_payload(): void
    {
        $service = new GovernanceService(new InMemoryProposalRepository());
        $first = $service->create(new Proposal('p7', 'entity-1', 'rekey', ['old_stable_key' => 'odo', 'new_stable_key' => 'nhk:brand:odo'], 'content-a', 1, 'deps-a', ProposalState::DRAFT, null, null, null, 'key-7', 1, null, null, '018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'brand'));
        $same = $service->create(new Proposal('p7b', 'entity-1', 'rekey', ['old_stable_key' => 'odo', 'new_stable_key' => 'nhk:brand:odo'], 'content-a', 1, 'deps-a', ProposalState::DRAFT, null, null, null, 'key-7', 1, null, null, '018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'brand'));
        self::assertSame($first->id, $same->id);

        $this->expectException(ProposalIdempotencyConflict::class);
        $service->create(new Proposal('p7c', 'entity-1', 'rekey', ['old_stable_key' => 'odo', 'new_stable_key' => 'nhk:brand:odo-2'], 'content-b', 1, 'deps-a', ProposalState::DRAFT, null, null, null, 'key-7', 1, null, null, '018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'brand'));
    }
}
