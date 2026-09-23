<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Capture\CaptureVideoProvenancePlanner;
use NHK\Core\Application\Capture\CaptureOrchestrationBudget;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Application\Governance\{CaptureDependencyStagingAdmission, OperationScopedStagingGuard, VideoStagingAdmission};
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Application\Video\{VideoEditorialGenerator, VideoEditorialResumePlanner, VideoSearchDocument, VideoSeoProjection, VideoService};
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle, PendingVideoProposalLookup, VideoProposalReconciliationPort};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class GovernedCaptureContinuationServiceTest extends TestCase
{
    public function test_unresolved_editorial_category_is_reviewable_not_retryable(): void
    {
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(['video']),
            static fn (): bool => true,
        );
        $method = new \ReflectionMethod($service, 'classifiedFailure');
        $method->setAccessible(true);

        $result = $method->invoke($service, ['proposal_id' => ''], new \RuntimeException('CATEGORY_UNRESOLVED'));

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['CATEGORY_UNRESOLVED'], $result['blockers']);

        $invalid = $method->invoke($service, ['proposal_id' => ''], new \NHK\Core\Domain\Video\VideoException('VIDEO_INTENDED_CATEGORY_INVALID'));
        self::assertSame('SYSTEM_BLOCKED', $invalid['status']);
        self::assertSame(['VIDEO_INTENDED_CATEGORY_INVALID'], $invalid['blockers']);
    }

    public function test_video_retry_does_not_reuse_pending_proposal_when_final_dependency_binding_changed(): void
    {
        $captureId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $old = new Proposal(
            UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId],
            'old-content', null, 'old-dependency', ProposalState::SUBMITTED,
            idempotencyKey: $captureId . ':video', entityType: 'video',
        );
        $lookup = new class($old) implements PendingVideoProposalLookup {
            public function __construct(private Proposal $proposal) {}
            public function findPendingVideoProposals(array $binding): array { return [$this->proposal]; }
        };
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(['video']),
            static fn (): bool => true,
            pendingVideoProposals: $lookup,
        );
        $method = new \ReflectionMethod($service, 'pendingVideoProposal');
        $method->setAccessible(true);
        $payload = [
            'canonical_id' => $videoId,
            'metadata' => [
                'semantic_attachments' => [[
                    'predicate' => 'about',
                    'target_type' => 'variant',
                    'target_uuid' => UuidCodec::newV7(),
                    'evidence_refs' => [['evidence_id' => $evidenceId]],
                ]],
            ],
        ];
        self::assertNull($method->invoke($service, ['idempotency_key' => $captureId . ':video'], $payload, $payload, $videoId));
    }

    public function test_video_retry_scope_is_derived_from_final_plan_and_dependency_closure(): void
    {
        $captureId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $captured = null;
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class), static fn (): array => [],
            $this->policies(['video']), static fn (): bool => true,
            videoScopeIssuer: static function (string $id, array $plan) use (&$captured): array {
                $captured = $plan;
                return ['approved' => true, 'capture_fingerprint' => hash('sha256', 'capture')];
            },
        );
        $method = new \ReflectionMethod($service, 'scopeVideoPlan');
        $method->setAccessible(true);
        $plan = $method->invoke($service, UuidCodec::newV7(), [
            'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
            'dependency_ids' => [UuidCodec::newV7(), UuidCodec::newV7()],
            'payload' => ['canonical_id' => $videoId, 'proposal_command_fingerprint' => hash('sha256', 'stale-command'), 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'TA2haJAn3EM']]],
        ], []);
        self::assertIsArray($captured);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($captured['plan_fingerprint'] ?? ''));
        self::assertTrue((bool) ($plan['payload']['staging_acceptance']['approved'] ?? false));
        self::assertSame((string) $captured['plan_fingerprint'], (string) ($plan['plan_fingerprint'] ?? ''));
        self::assertArrayNotHasKey('proposal_command_fingerprint', $plan['payload']);
    }

    public function test_existing_capture_video_resume_does_not_turn_applied_missing_evidence_receipt_into_a_proposal_skip(): void
    {
        $captureId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'old-content', null, 'old-dependency', ProposalState::APPLIED, idempotencyKey: $captureId . ':video', entityType: 'video');
        $lookup = new class($proposal) implements PendingVideoProposalLookup {
            public function __construct(private Proposal $proposal) {}
            public function findPendingVideoProposals(array $binding): array { return [$this->proposal]; }
        };
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(['video']),
            static fn (): bool => true,
            videoProvenance: new CaptureVideoProvenancePlanner(),
            pendingVideoProposals: $lookup,
        );
        $plans = new \ReflectionMethod($service, 'plans');
        $plans->setAccessible(true);

        $result = $plans->invoke($service, $captureId, 'capture:resume:video', [
            'existing_capture_continuation' => true,
            'phase_receipts' => ['VIDEO_EVIDENCE_GOVERNANCE' => ['status' => 'COMPLETED', 'result' => 'APPLIED', 'attempt_no' => 2]],
            'content_intent' => ['intent' => 'VIDEO'],
            'subject_resolution' => ['primary' => ['id' => UuidCodec::newV7(), 'type' => 'variant', 'revision' => 1], 'resolved' => []],
            'assets' => [['kind' => 'video', 'video_proposal' => [
                'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
                'payload' => ['canonical_id' => $videoId, 'metadata' => [
                    'source' => ['platform' => 'youtube', 'external_video_id' => '2EMuIG2RfTg', 'canonical_source_url' => 'https://www.youtube.com/watch?v=2EMuIG2RfTg'],
                    'semantic_attachments' => [['predicate' => 'about', 'target_type' => 'variant', 'target_uuid' => UuidCodec::newV7(), 'evidence_refs' => []]],
                ]],
            ]]],
        ], false);

        self::assertCount(1, $result);
        self::assertArrayHasKey('capture_video_provenance', $result[0]);
        self::assertArrayNotHasKey('proposal_id', $result[0]);
    }

    public function test_video_asset_without_explicit_semantic_delta_does_not_become_knowledge_delta(): void
    {
        $videoId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(),
            static fn (): bool => true,
        );
        $plans = new \ReflectionMethod($service, 'plans');
        $plans->setAccessible(true);

        $result = $plans->invoke($service, 'capture-video-golden', 'resume-video', [
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => [
                    'entity_type' => 'video',
                    'operation' => 'ingest',
                    'payload' => ['canonical_id' => $videoId],
                ],
            ]],
            'subject_resolution' => [
                'primary' => ['id' => $subjectId, 'type' => 'variant', 'revision' => 1],
                'resolved' => [['id' => $subjectId, 'type' => 'variant', 'revision' => 1]],
            ],
            'interpretation' => ['user_claim_candidates' => [[
                'text' => 'Odo 36 có ba phiên bản vách máy.',
                'scope' => 'variant',
                'facet' => 'configuration',
                'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            ]]],
        ]);

        self::assertNotEmpty($result);
        self::assertSame(['video'], array_values(array_unique(array_map(
            static fn (array $plan): string => (string) ($plan['entity_type'] ?? (isset($plan['capture_video_provenance']) ? 'video' : 'unknown')),
            $result,
        ))));
    }

    public function test_applied_evidence_receipt_is_not_reused_when_canonical_owner_is_missing(): void
    {
        $evidenceId = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        $sourceId = UuidCodec::newV7();
        $emptyClaims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $emptySources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $emptyEvidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(),
            static fn (): bool => true,
            canonicalDependencies: new CanonicalDependencyValidator($emptyClaims, $emptySources, $emptyEvidence),
            videoDependencyState: static fn (): array => [
                'evidence' => [['canonical_id' => $evidenceId, 'claim_id' => $claimId, 'source_id' => $sourceId, 'active' => true, 'revision' => 1]],
            ],
        );
        $reuse = new \ReflectionMethod($service, 'reusedDependency');
        $reuse->setAccessible(true);
        $result = $reuse->invoke($service, [
            'payload' => ['claim_id' => $claimId, 'source_id' => $sourceId],
        ], [], 'evidence', 'VIDEO_EVIDENCE_GOVERNANCE');

        self::assertNull($result);
        $key = new \ReflectionMethod($service, 'evidenceRecoveryKey');
        $key->setAccessible(true);
        self::assertSame(
            $key->invoke($service, ['payload' => ['claim_id' => $claimId, 'source_id' => $sourceId, 'excerpt' => 'x']]),
            $key->invoke($service, ['payload' => ['source_id' => $sourceId, 'claim_id' => $claimId, 'excerpt' => 'x']]),
        );
    }

    public function test_video_plan_attaches_server_issued_scope_without_replacing_owner_subject_id(): void
    {
        $captureId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $semanticSubjectId = UuidCodec::newV7();
        $scope = [
            'approved' => true,
            'operation_family' => 'governed_video_plan',
            'operation' => 'ingest',
            'capture_id' => $captureId,
            'capture_fingerprint' => hash('sha256', 'capture'),
            'proposed_uuid' => $videoId,
            'subject' => ['type' => 'classification', 'uuid' => $semanticSubjectId, 'revision' => 1],
        ];
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(),
            static fn (string $capability): bool => true,
            videoScopeIssuer: static fn (string $issuedCaptureId, array $plan): array => $scope,
        );
        $plans = new \ReflectionMethod($service, 'plans');
        $plans->setAccessible(true);

        $result = $plans->invoke($service, $captureId, 'video-live-shape', [
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => [
                    'entity_type' => 'video',
                    'operation' => 'ingest',
                    'subject_id' => $videoId,
                    'payload' => [
                        'canonical_id' => $videoId,
                        'metadata' => [
                            'source' => ['platform' => 'youtube', 'external_video_id' => '2EMuIG2RfTg', 'canonical_source_url' => 'https://www.youtube.com/watch?v=2EMuIG2RfTg'],
                            'subject_resolution_packet' => ['type' => 'classification', 'id' => $semanticSubjectId, 'revision' => 1],
                        ],
                    ],
                ],
            ]],
        ], false);

        self::assertCount(1, $result);
        self::assertSame($videoId, $result[0]['subject_id']);
        self::assertSame($videoId, $result[0]['payload']['canonical_id']);
        self::assertSame($scope, $result[0]['payload']['staging_acceptance']);
    }

    public function test_video_provenance_plan_attaches_scope_before_final_video_governance(): void
    {
        $captureId = '01a0b384-6a83-7f99-b231-d784b9ab9542';
        $videoId = '01a0b384-6e09-71a6-8f58-df48654d6aee';
        $subjectId = '01a09e44-539a-7f1a-938a-d7d91bb689a3';
        $captureFingerprint = hash('sha256', 'live-video-provenance-capture');
        $videoPlanFingerprint = hash('sha256', 'live-video-provenance-plan');
        $commandFingerprint = hash('sha256', 'live-video-provenance-command');
        $video = new Video($videoId, 'youtube', '2Fx8Wp4Hzyk', 'https://www.youtube.com/watch?v=2Fx8Wp4Hzyk', revision: 1);
        $videos = new class($video) implements VideoRepository {
            public function __construct(private Video $video) {}
            public function findByCanonicalId(string $id): ?Video { return $id === $this->video->canonicalId ? $this->video : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $this->video = $video; }
            public function update(Video $video, int $expectedRevision): Video { return $this->video = $video; }
            public function list(bool $includeRetired = false): array { return [$this->video]; }
        };
        $capture = new \NHK\Core\Domain\Capture\CaptureRecord($captureId, 'live-video-provenance', $captureFingerprint, 'SEMANTICS_RECONCILED', 'IN_PROGRESS', null, null, [[
            'kind' => 'video',
            'video_proposal' => [
                'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
                'fingerprint' => $videoPlanFingerprint, 'proposal_command_fingerprint' => $commandFingerprint,
                'payload' => [
                    'canonical_id' => $videoId,
                    'metadata' => [
                        'source' => ['platform' => 'youtube', 'external_video_id' => '2Fx8Wp4Hzyk', 'canonical_source_url' => 'https://www.youtube.com/watch?v=2Fx8Wp4Hzyk', 'source_title' => 'Đồng hồ công cộng'],
                        'subject_resolution_packet' => ['status' => 'RESOLVED', 'match' => 'uuid_exact', 'type' => 'classification', 'id' => $subjectId, 'name' => 'Đồng hồ công cộng', 'stable_key' => 'nhk:classification:clock-type.dong-ho-cong-cong', 'revision' => 2],
                    ],
                ],
            ],
        ]], ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']], revision: 43);
        $scopeVerifier = new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging',
            'test-secret',
            static fn (array $scope, \NHK\Core\Domain\Capture\CaptureRecord $capture): bool => (new VideoStagingAdmission($videos))(false, $scope, $capture, [], []),
            can: static fn (): bool => true,
            videos: $videos,
        );
        $issued = 0;
        $createdVideoPayload = null;
        $proposalId = UuidCodec::newV7();
        $governance = new class($proposalId, $createdVideoPayload) implements \NHK\Core\Contracts\Governance\GovernedLifecycle {
            public function __construct(private string $proposalId, private mixed &$createdVideoPayload) {}
            public function createFromArguments(array $arguments): Proposal
            {
                if (($arguments['entity_type'] ?? '') === 'video') $this->createdVideoPayload = $arguments['payload'] ?? null;
                return new Proposal($this->proposalId, (string) ($arguments['subject_id'] ?? 'subject'), (string) ($arguments['operation'] ?? 'ingest'), (array) ($arguments['payload'] ?? []), 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: (string) ($arguments['idempotency_key'] ?? 'video'), targetUuid: (string) ($arguments['payload']['canonical_id'] ?? ''), entityType: (string) ($arguments['entity_type'] ?? ''));
            }
            public function submit(string $id): Proposal { throw new \LogicException('submit not expected'); }
            public function review(string $id): array { return ['state' => 'approved', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => 'subject', 'target_uuid' => '', 'payload' => $this->createdVideoPayload ?? [], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']; }
            public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { throw new \LogicException('approve not expected'); }
            public function eligibility(string $id): array { return ['ready' => true]; }
        };
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (string $id): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'entity_type' => 'video', 'active' => true, 'revision' => 1]],
            $this->policies(['video'], ['video' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            null,
            new CaptureVideoProvenancePlanner(),
            null,
            static function (array $plan): array {
                $payload = is_array($plan['dependencies'][2]['payload'] ?? null) ? $plan['dependencies'][2]['payload'] : [];
                return ['source' => ['canonical_id' => '11111111-1111-4111-8111-111111111111', 'revision' => 1, 'active' => true], 'claim' => ['canonical_id' => '22222222-2222-4222-8222-222222222222', 'revision' => 1, 'active' => true], 'evidence' => [['canonical_id' => '33333333-3333-4333-8333-333333333333', 'source_id' => $payload['source_id'] ?? '', 'claim_id' => $payload['claim_id'] ?? '', 'revision' => 1, 'active' => true]]];
            },
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            static function (string $issuedCaptureId, array $plan) use (&$issued, $scopeVerifier, $capture): array {
                $issued++;
                return $scopeVerifier->issueForVideoPlan($capture, $plan);
            },
        );

        $result = $service->execute($captureId, 'continuation:live-video', [
            'content_intent' => ['intent' => 'VIDEO'],
            'assets' => $capture->assets,
            'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'classification', 'match' => 'uuid_exact', 'revision' => 2], 'resolved' => []],
        ]);

        self::assertSame('APPLIED', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $issued);
        self::assertIsArray($createdVideoPayload);
        self::assertArrayHasKey('staging_acceptance', $createdVideoPayload);
        self::assertSame($videoId, $createdVideoPayload['staging_acceptance']['proposed_uuid']);
        self::assertSame(0, $createdVideoPayload['staging_acceptance']['expected_revision']);
        self::assertSame('video:ingest', 'video:' . $createdVideoPayload['staging_acceptance']['operation']);
    }

    public function test_live_shaped_video_provenance_scopes_new_dependencies_before_final_video_command(): void
    {
        $captureId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $capture = new \NHK\Core\Domain\Capture\CaptureRecord($captureId, 'live-shaped', hash('sha256', 'live-shaped'), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']], assets: [[
            'kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'idempotency_key' => 'live-shaped:video', 'fingerprint' => hash('sha256', 'video-plan'), 'payload' => [
                'canonical_id' => $videoId, 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'GHvh8-iXPoE', 'canonical_source_url' => 'https://www.youtube.com/watch?v=GHvh8-iXPoE', 'source_title' => 'Variant A'], 'subject_resolution_packet' => ['status' => 'RESOLVED', 'match' => 'uuid_exact', 'type' => 'variant', 'id' => $subjectId, 'name' => 'Variant A', 'revision' => 1]],
            ]],
        ]]);
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $admission = static function (array $scope, \NHK\Core\Domain\Capture\CaptureRecord $record, array $input, array $assets) use ($videos): bool {
            return (new CaptureDependencyStagingAdmission())(false, $scope, $record, $input, $assets)
                || (new VideoStagingAdmission($videos))(false, $scope, $record, $input, $assets);
        };
        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', $admission, can: static fn (): bool => true, videos: $videos);
        $created = [];
        $proposals = [];
        $governance = new class($created, $proposals) implements GovernedLifecycle {
            public function __construct(private array &$created, private array &$proposals) {}
            public function createFromArguments(array $arguments): Proposal { $id = UuidCodec::newV7(); $this->created[] = $arguments; return $this->proposals[$id] = new Proposal($id, (string) $arguments['subject_id'], (string) $arguments['operation'], (array) $arguments['payload'], 'content', $arguments['expected_revision'] ?? null, 'dependency', ProposalState::APPROVED, idempotencyKey: (string) $arguments['idempotency_key'], targetUuid: ($arguments['entity_type'] ?? '') === 'video' ? (string) ($arguments['payload']['canonical_id'] ?? '') : null, entityType: (string) $arguments['entity_type']); }
            public function submit(string $id): Proposal { return $this->proposals[$id]; }
            public function review(string $id): array { $p = $this->proposals[$id]; return ['state' => 'approved', 'entity_type' => $p->entityType, 'operation' => $p->operation, 'subject_id' => $p->subjectId, 'target_uuid' => $p->targetUuid, 'payload' => $p->payload, 'content_fingerprint' => $p->contentFingerprint, 'dependency_fingerprint' => $p->dependencyFingerprint]; }
            public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->proposals[$id]; }
            public function eligibility(string $id): array { return ['ready' => true]; }
        };
        $service = new GovernedCaptureContinuationService(
            $governance,
            static function (string $proposalId) use (&$proposals, $verifier): array { $proposal = $proposals[$proposalId]; (new OperationScopedStagingGuard(static fn (): string => 'staging', static fn (): bool => true, scopeVerifier: [$verifier, 'verifyProposal']))->assertAllowed($proposal); $id = UuidCodec::newV7(); return ['canonical_id' => $id, 'canonical_readback' => ['canonical_id' => $id, 'entity_type' => $proposal->entityType, 'active' => true, 'revision' => 1]]; },
            $this->policies(['source', 'knowledge', 'evidence', 'video'], ['source' => 'AUTO_PUBLISH', 'knowledge' => 'AUTO_PUBLISH', 'evidence' => 'AUTO_PUBLISH', 'video' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            null,
            new CaptureVideoProvenancePlanner(),
            videoScopeIssuer: static function (string $id, array $plan) use ($verifier, $capture): array { return $verifier->issueForVideoPlan($capture, $plan); },
            dependencyScopeIssuer: static function (string $id, array $plan) use ($verifier, $capture): array { return $verifier->issueForCaptureDependencyPlan($capture, $plan); },
        );
        $result = $service->execute($captureId, 'live-shaped', ['content_intent' => ['intent' => 'VIDEO'], 'assets' => $capture->assets, 'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'variant', 'match' => 'uuid_exact', 'revision' => 1], 'resolved' => []]]);
        self::assertSame('APPLIED', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertCount(4, $created);
        $videoCommands = array_values(array_filter($created, static fn (array $command): bool => ($command['entity_type'] ?? '') === 'video'));
        self::assertCount(1, $videoCommands);
        self::assertSame('ingest', $videoCommands[0]['operation']);
        self::assertSame(0, $videoCommands[0]['payload']['staging_acceptance']['expected_revision']);
        self::assertSame('ingest', $videoCommands[0]['payload']['staging_acceptance']['create_semantics']);
        self::assertArrayHasKey('staging_acceptance', $videoCommands[0]['payload']);
    }

    public function test_existing_capture_continuation_runs_governance_and_requires_explicit_approval(): void
    {
        $variant = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $variant, 'ingest', ['text' => 'Côn chữ U màu trắng.'], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'continuation:knowledge:0', entityType: 'knowledge');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->expects(self::once())->method('submit')->with($proposal->id)->willReturn($proposal);
        $governance->expects(self::never())->method('approve');
        $apply = static function (): array { throw new \LogicException('apply must not run before approval'); };
        $service = new GovernedCaptureContinuationService($governance, $apply, $this->policies(), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'continuation', ['subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]], 'interpretation' => ['user_claim_candidates' => [['text' => 'Côn chữ U màu trắng.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]], 'observations' => []]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['GOVERNANCE_APPROVAL_REQUIRED'], $result['blockers']);
        self::assertSame($proposal->id, $result['writes'][0]['proposal_id']);
    }

    public function test_knowledge_delta_supports_exact_classification_subject_with_entity_scope(): void
    {
        $subject = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $subject, 'ingest', ['text' => 'Đồng hồ công cộng phục vụ nhiều người.'], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'continuation:classification-knowledge', entityType: 'knowledge');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static function (array $arguments) use ($subject): bool {
            return ($arguments['entity_type'] ?? '') === 'knowledge'
                && ($arguments['subject_id'] ?? '') === $subject
                && ($arguments['payload']['provenance']['metadata']['subject_type'] ?? '') === 'classification'
                && ($arguments['payload']['provenance']['metadata']['scope'] ?? '') === 'entity';
        }))->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
        );
        $governance->expects(self::once())->method('submit')->with($proposal->id)->willReturn($proposal->transition(ProposalState::SUBMITTED));
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => [], $this->policies(), static fn (): bool => true);

        $result = $service->execute('capture-classification', 'continuation:classification', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $subject, 'type' => 'classification']]],
            'continuation_delta_text' => 'Đồng hồ công cộng phục vụ nhiều người.',
            'interpretation' => [],
            'observations' => [],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame($proposal->id, $result['writes'][0]['proposal_id']);
    }

    public function test_article_continuation_plans_one_governed_about_relation_for_exact_primary_subject(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $plans = (new \ReflectionMethod($service, 'plans'));
        $plans->setAccessible(true);
        $subject = UuidCodec::newV7();
        $planned = $plans->invoke($service, 'capture-article', 'continuation', [
            'article_id' => 485,
            'article_endpoint_key' => '1:485',
            'content_intent' => ['intent' => 'TEXT_ARTICLE'],
            'subject_resolution' => ['primary' => ['id' => $subject, 'type' => 'classification'], 'resolved' => [['id' => $subject, 'type' => 'classification']]],
        ], true);

        self::assertCount(1, $planned);
        self::assertSame('relation_create', $planned[0]['operation']);
        self::assertSame($subject, $planned[0]['payload']['target_uuid']);
    }

    public function test_capture_provenance_packets_plan_source_and_resolved_evidence_without_reparsing_claim_text(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $plans = (new \ReflectionMethod($service, 'plans'));
        $plans->setAccessible(true);
        $claimId = UuidCodec::newV7();
        $sourceId = UuidCodec::newV7();
        $planned = $plans->invoke($service, 'capture-knowledge', 'continuation', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'provenance_packets' => [
                'sources' => [[
                    'stable_key' => 'nhk:source:public-clock:ahs-turret-group',
                    'title' => 'AHS Turret Clock Group',
                    'source_type' => 'website',
                    'locator' => 'https://www.ahsoc.org/groups/turret-clock-group/about-the-turret-clock-group/',
                    'metadata' => ['visibility' => 'PUBLIC'],
                ]],
                'evidence' => [[
                    'claim_id' => $claimId,
                    'source_id' => $sourceId,
                    'excerpt' => 'The source describes turret clocks and public timekeeping.',
                    'relation' => 'supports',
                    'locator' => 'https://www.ahsoc.org/groups/turret-clock-group/about-the-turret-clock-group/',
                    'metadata' => ['visibility' => 'PUBLIC'],
                ]],
            ],
            'subject_resolution' => ['resolved' => []],
        ], false);

        self::assertCount(2, $planned);
        self::assertSame(['source', 'evidence'], array_column($planned, 'entity_type'));
        self::assertSame('nhk:source:public-clock:ahs-turret-group', $planned[0]['payload']['stable_key']);
        self::assertSame($claimId, $planned[1]['payload']['claim_id']);
        self::assertSame($sourceId, $planned[1]['payload']['source_id']);
    }

    public function test_video_review_required_exposes_governance_and_canonical_identity_separately(): void
    {
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:pending:video', targetUuid: $videoId, entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposalId)->willReturnOnConsecutiveCalls(
            ['proposal_id' => $proposalId, 'state' => 'draft', 'entity_type' => 'video', 'target_uuid' => $videoId, 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['proposal_id' => $proposalId, 'state' => 'submitted', 'entity_type' => 'video', 'target_uuid' => $videoId, 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
        );
        $governance->expects(self::once())->method('submit')->with($proposalId)->willReturn($proposal->transition(ProposalState::SUBMITTED));
        $governance->expects(self::never())->method('approve');
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => throw new \LogicException('apply must not run before approval'), $this->policies(['video']), static fn (): bool => true);

        $result = $service->execute('capture-pending', 'capture-pending:video', [
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => ['canonical_id' => $videoId]]]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame($proposalId, $result['writes'][0]['proposal_id']);
        self::assertSame('submitted', $result['writes'][0]['proposal_state']);
        self::assertSame($videoId, $result['writes'][0]['target_uuid']);
        self::assertNull($result['writes'][0]['canonical_id']);
        self::assertSame($proposalId, $result['proposal_id']);
        self::assertSame('submitted', $result['proposal_state']);
        self::assertSame($videoId, $result['target_uuid']);
        self::assertNull($result['canonical_id']);
    }

    public function test_existing_capture_continuation_applies_only_after_governance_and_readback(): void
    {
        $proposalId = UuidCodec::newV7();
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn(['state' => 'approved', 'entity_type' => 'relation', 'operation' => 'relation_create', 'subject_id' => 'relation', 'payload' => [], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency', 'revision' => 2]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn(['ready' => true]);
        $applied = false;
        $service = new GovernedCaptureContinuationService($governance, static function (string $id) use (&$applied): array { $applied = true; return ['canonical_id' => $id, 'canonical_readback' => ['canonical_id' => $id, 'revision' => 1], 'idempotent' => false]; }, $this->policies(['relation']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'continuation', [], ['proposal_ids' => [$proposalId]]);

        self::assertTrue($applied);
        self::assertSame('APPLIED', $result['status']);
        self::assertSame($proposalId, $result['writes'][0]['proposal_id']);
    }

    public function test_stale_relation_binding_reenters_generic_governed_reconciliation_boundary(): void
    {
        $proposalId = UuidCodec::newV7();
        $replacementId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, '1:485', 'relation_create', [
            'source_type' => 'wp_post', 'source_uuid' => '1:485',
            'target_type' => 'classification', 'target_uuid' => UuidCodec::newV7(),
            'predicate' => 'about', 'source_revision' => 1, 'target_revision' => 1,
        ], 'content', null, 'dependency', ProposalState::APPROVED, entityType: 'relation');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'approved', 'entity_type' => 'relation', 'operation' => 'relation_create',
            'subject_id' => '1:485', 'payload' => $proposal->payload,
            'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency', 'revision' => 1,
        ]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn([
            'ready' => false, 'reasons' => ['TARGET_REVISION_CHANGED'],
        ]);
        $reconciled = false;
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('stale relation must not apply'),
            $this->policies(['relation']),
            static fn (string $capability): bool => true,
            proposalReconciliation: static function (Proposal $stale, array $eligibility, array $control) use (&$reconciled, $proposalId, $replacementId): array {
                $reconciled = true;
                return ['proposal_id' => $replacementId, 'status' => 'APPLIED', 'replaced_proposal_id' => $proposalId, 'canonical_id' => 'edge-1', 'canonical_readback' => ['canonical_id' => 'edge-1', 'active' => true]];
            },
        );

        $result = $service->execute('capture-485', 'continuation', [], ['proposal_ids' => [$proposalId]]);

        self::assertTrue($reconciled);
        self::assertSame('APPLIED', $result['status']);
        self::assertSame($replacementId, $result['writes'][0]['proposal_id']);
    }

    public function test_stale_video_update_is_not_dispatched_to_relation_reconciliation(): void
    {
        $proposalId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'update', [
            'canonical_id' => $videoId,
            'metadata' => ['semantic_reconciliation_requested' => true],
        ], 'content', 1, 'dependency', ProposalState::APPROVED, entityType: 'video', targetUuid: $videoId);
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'approved',
            'entity_type' => 'video',
            'operation' => 'update',
            'subject_id' => $videoId,
            'target_uuid' => $videoId,
            'payload' => $proposal->payload,
            'expected_revision' => 1,
            'content_fingerprint' => 'content',
            'dependency_fingerprint' => 'dependency',
            'revision' => 1,
        ]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn([
            'ready' => false,
            'reasons' => ['TARGET_REVISION_CHANGED'],
        ]);

        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('stale video update must not apply'),
            $this->policies(['video'], ['video' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            proposalReconciliation: static fn (): array => [
                'status' => 'SYSTEM_BLOCKED',
                'blockers' => ['RELATION_RECONCILIATION_UNSUPPORTED'],
            ],
        );

        $result = $service->execute('capture-video', 'continuation', [], ['proposal_ids' => [$proposalId]]);

        self::assertSame('SYSTEM_BLOCKED', $result['status']);
        self::assertSame(['TARGET_REVISION_CHANGED'], $result['blockers']);
    }

    public function test_existing_supported_claim_is_reused_before_continuation_proposal_creation(): void
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::never())->method('createFromArguments');
        $variant = '95873bfe-d978-4eda-a5a2-ce9ba79625df';
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => [], $this->policies(), static fn (string $capability): bool => true, new ClaimReusePolicy());

        $result = $service->execute('capture-355', 'addendum-configuration', [
            'subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [[
                'text' => 'Cấu hình 10 côn 10 búa, chơi 2 bài nhạc.',
                'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            ]]],
            'retrieval' => ['selected_claims' => [[
                'claim_id' => '01a06d45-aa68-7d08-b6a0-7cccb84ae75b',
                'claim_revision' => 2,
                'text' => 'Một hiện vật được Bibelot & Co mô tả là Odo n°36, serial 4583, có 10 côn/tiges, 10 búa/marteaux và hai giai điệu.',
                'subject_id' => $variant,
                'scope' => 'variant',
                'provenance' => 'CATALOG_SUPPORTED',
                'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            ]]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame([], $result['writes']);
        self::assertSame('01a06d45-aa68-7d08-b6a0-7cccb84ae75b', $result['reused_claims'][0]['claim_id']);
    }

    public function test_auto_publish_applies_new_capture_claim_and_reads_back_canonical_owner(): void
    {
        $variant = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $variant, 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:knowledge', entityType: 'knowledge');
        $relationProposal = new Proposal(UuidCodec::newV7(), 'relation', 'relation_create', [], 'relation-content', null, 'relation-dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:relation', entityType: 'relation');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::exactly(2))->method('createFromArguments')->willReturnOnConsecutiveCalls($proposal, $relationProposal);
        $governance->expects(self::exactly(2))->method('submit')->willReturnOnConsecutiveCalls($proposal, $relationProposal);
        $governance->expects(self::exactly(4))->method('review')->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'draft', 'entity_type' => 'relation', 'content_fingerprint' => 'relation-content', 'dependency_fingerprint' => 'relation-dependency'],
            ['state' => 'submitted', 'entity_type' => 'relation', 'content_fingerprint' => 'relation-content', 'dependency_fingerprint' => 'relation-dependency'],
        );
        $governance->expects(self::exactly(2))->method('approve')->willReturnOnConsecutiveCalls($proposal->transition(ProposalState::APPROVED, 'system'), $relationProposal->transition(ProposalState::APPROVED, 'system'));
        $governance->expects(self::exactly(2))->method('eligibility')->willReturn(['ready' => true]);
        $applied = [];
        $service = new GovernedCaptureContinuationService($governance, static function (string $id) use (&$applied): array {
            $applied[] = $id;
            $isRelation = count($applied) === 2;
            return ['canonical_id' => $isRelation ? 'edge-1' : 'claim-1', 'canonical_readback' => ['canonical_id' => $isRelation ? 'edge-1' : 'claim-1', 'entity_type' => $isRelation ? 'relation' : 'knowledge', 'active' => true, 'revision' => 1]];
        }, $this->policies(['knowledge', 'relation'], ['knowledge' => 'AUTO_PUBLISH', 'relation' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'capture-1:semantic', [
            'subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Cấu hình 10 côn.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]],
            'observations' => [],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame([$proposal->id, $relationProposal->id], $applied);
        self::assertSame(['canonical_id' => 'claim-1', 'entity_type' => 'knowledge', 'active' => true, 'revision' => 1], $result['writes'][0]['canonical_readback']);
    }

    public function test_production_shaped_knowledge_delta_scopes_two_candidates_from_persisted_capture_and_rejects_tampering(): void
    {
        $captureId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $capture = new \NHK\Core\Domain\Capture\CaptureRecord(
            $captureId,
            'live-shaped:knowledge-delta',
            hash('sha256', 'live-shaped:knowledge-delta'),
            'SEMANTICS_RECONCILED',
            'IN_PROGRESS',
            context: ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'KNOWLEDGE_DELTA']],
            revision: 3,
        );
        $admission = static fn (array $scope, \NHK\Core\Domain\Capture\CaptureRecord $record, array $input, array $assets): bool
            => (new CaptureDependencyStagingAdmission())(false, $scope, $record, $input, $assets)
                || (new \NHK\Core\Application\Governance\CaptureChildRelationStagingAdmission())(false, $scope, $record, $input, $assets);
        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', $admission, can: static fn (): bool => true);
        $proposals = [];
        $governance = new class($proposals) implements GovernedLifecycle {
            public function __construct(private array &$proposals) {}
            public function createFromArguments(array $arguments): Proposal
            {
                $id = UuidCodec::newV7();
                $proposal = new Proposal($id, (string) $arguments['subject_id'], (string) $arguments['operation'], (array) $arguments['payload'], 'content', $arguments['expected_revision'] ?? null, 'dependency', ProposalState::APPROVED, idempotencyKey: (string) $arguments['idempotency_key'], targetUuid: null, entityType: (string) $arguments['entity_type']);
                $this->proposals[$id] = $proposal;
                return $proposal;
            }
            public function submit(string $id): Proposal { return $this->proposals[$id]; }
            public function review(string $id): array { $p = $this->proposals[$id]; return ['state' => 'approved', 'entity_type' => $p->entityType, 'operation' => $p->operation, 'subject_id' => $p->subjectId, 'target_uuid' => $p->targetUuid, 'payload' => $p->payload, 'content_fingerprint' => $p->contentFingerprint, 'dependency_fingerprint' => $p->dependencyFingerprint]; }
            public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->proposals[$id]; }
            public function eligibility(string $id): array { return ['ready' => true]; }
        };
        $service = new GovernedCaptureContinuationService(
            $governance,
            static function (string $proposalId) use (&$proposals, $verifier): array {
                $proposal = $proposals[$proposalId];
                if (in_array($proposal->entityType, ['knowledge', 'relation'], true)) (new OperationScopedStagingGuard(static fn (): string => 'staging', static fn (): bool => true, scopeVerifier: [$verifier, 'verifyProposal']))->assertAllowed($proposal);
                return ['canonical_id' => UuidCodec::newV7(), 'canonical_readback' => ['canonical_id' => UuidCodec::newV7(), 'entity_type' => $proposal->entityType, 'active' => true, 'revision' => 1]];
            },
            $this->policies(['knowledge', 'relation'], ['knowledge' => 'AUTO_PUBLISH', 'relation' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            dependencyScopeIssuer: static function (string $id, array $plan) use ($verifier, $capture): array { return $verifier->issueForCaptureDependencyPlan($capture, $plan); },
            relationScopeIssuer: static function (string $id, array $plan) use ($verifier, $capture): array { return $verifier->issueForCaptureChildRelation($capture, $plan); },
        );

        $result = $service->execute($captureId, 'capture:knowledge-delta', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'classification', 'revision' => 3], 'resolved' => [['id' => $subjectId, 'type' => 'classification', 'revision' => 3]]],
            'interpretation' => ['user_claim_candidates' => [
                ['text' => 'Mặt số màu xanh.', 'facet' => 'identity', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE'],
                ['text' => 'Có lịch đánh chuông theo giờ.', 'facet' => 'identity', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE'],
            ]],
            'observations' => [],
        ]);

        self::assertSame('APPLIED', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $knowledge = array_values(array_filter($proposals, static fn (Proposal $proposal): bool => $proposal->entityType === 'knowledge'));
        self::assertCount(2, $knowledge);
        foreach ($knowledge as $proposal) {
            self::assertSame($captureId, $proposal->payload['capture_id']);
            self::assertSame(3, $proposal->payload['capture_revision']);
            self::assertArrayHasKey('staging_acceptance', $proposal->payload);
            self::assertNotSame('MISSING_PARENT_PROVENANCE', $result['blockers'][0] ?? null);
        }
        $relations = array_values(array_filter($proposals, static fn (Proposal $proposal): bool => $proposal->entityType === 'relation'));
        self::assertCount(2, $relations);
        foreach ($relations as $relation) {
            self::assertSame('knowledge', $relation->payload['source_type']);
            self::assertSame(1, $relation->payload['source_revision']);
            self::assertSame(3, $relation->payload['target_revision']);
            self::assertArrayHasKey('staging_acceptance', $relation->payload);
            self::assertSame('capture_child_relation', $relation->payload['staging_acceptance']['operation_family']);
        }

        $original = $knowledge[0];
        $mutations = [
            static function (Proposal $p): Proposal { $payload = $p->payload; $payload['capture_id'] = UuidCodec::newV7(); return new Proposal($p->id, $p->subjectId, $p->operation, $payload, $p->contentFingerprint, $p->expectedRevision, $p->dependencyFingerprint, $p->state, idempotencyKey: $p->idempotencyKey, entityType: $p->entityType, targetUuid: $p->targetUuid); },
            static function (Proposal $p): Proposal { $payload = $p->payload; $payload['capture_revision'] = 99; return new Proposal($p->id, $p->subjectId, $p->operation, $payload, $p->contentFingerprint, $p->expectedRevision, $p->dependencyFingerprint, $p->state, idempotencyKey: $p->idempotencyKey, entityType: $p->entityType, targetUuid: $p->targetUuid); },
            static function (Proposal $p): Proposal { $payload = $p->payload; $payload['text'] = 'tampered'; return new Proposal($p->id, $p->subjectId, $p->operation, $payload, $p->contentFingerprint, $p->expectedRevision, $p->dependencyFingerprint, $p->state, idempotencyKey: $p->idempotencyKey, entityType: $p->entityType, targetUuid: $p->targetUuid); },
            static function (Proposal $p): Proposal { return new Proposal($p->id, UuidCodec::newV7(), $p->operation, $p->payload, $p->contentFingerprint, $p->expectedRevision, $p->dependencyFingerprint, $p->state, idempotencyKey: $p->idempotencyKey, entityType: $p->entityType, targetUuid: $p->targetUuid); },
        ];
        foreach ($mutations as $mutate) self::assertFalse($verifier->verifyProposal($original->payload['staging_acceptance'], $mutate($original)));
        $tamperedScope = $original->payload['staging_acceptance'];
        $tamperedScope['fingerprint'] = hash('sha256', 'changed-scope');
        self::assertFalse($verifier->verifyProposal($tamperedScope, $original));

        $relation = $relations[0];
        foreach (['capture_id', 'source_uuid', 'target_uuid', 'predicate', 'source_revision', 'target_revision', 'capture_revision'] as $field) {
            $payload = $relation->payload;
            $payload[$field] = in_array($field, ['source_revision', 'target_revision', 'capture_revision'], true) ? 99 : ($field === 'predicate' ? 'classified_as' : UuidCodec::newV7());
            $tampered = new Proposal($relation->id, $relation->subjectId, $relation->operation, $payload, $relation->contentFingerprint, $relation->expectedRevision, $relation->dependencyFingerprint, $relation->state, idempotencyKey: $relation->idempotencyKey, entityType: $relation->entityType, targetUuid: $relation->targetUuid);
            self::assertFalse($verifier->verifyProposal($relation->payload['staging_acceptance'], $tampered), $field);
        }
        $payload = $relation->payload;
        $payload['provenance']['origin'] = 'TAMPERED';
        $tampered = new Proposal($relation->id, $relation->subjectId, $relation->operation, $payload, $relation->contentFingerprint, $relation->expectedRevision, $relation->dependencyFingerprint, $relation->state, idempotencyKey: $relation->idempotencyKey, entityType: $relation->entityType, targetUuid: $relation->targetUuid);
        self::assertFalse($verifier->verifyProposal($relation->payload['staging_acceptance'], $tampered));
    }

    public function test_auto_publish_submits_video_proposal_from_capture_asset_without_duplicate_writer(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:auto:video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $args): bool => ($args['entity_type'] ?? '') === 'video' && ($args['operation'] ?? '') === 'ingest'))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static function (string $id) use ($videoId): array { return ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'entity_type' => 'video', 'active' => true, 'revision' => 1]]; }, $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-1', 'capture-1:semantic', ['subject_resolution' => ['resolved' => []], 'interpretation' => [], 'observations' => [], 'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]]]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_readback']['canonical_id']);
    }

    public function test_video_resolved_subject_without_semantic_delta_is_not_blocked(): void
    {
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => throw new \LogicException('no Knowledge write is allowed'),
            $this->policies(['video']),
            static fn (): bool => true,
        );

        $result = $service->execute('capture-video-subject-only', 'capture-video-subject-only:semantic', [
            'content_intent' => ['intent' => 'VIDEO', 'semantic_delta' => ['status' => 'NONE']],
            'subject_resolution_packet' => [
                'status' => 'resolved',
                'canonical_subject_id' => UuidCodec::newV7(),
                'entity_type' => 'variant',
                'revision' => 3,
            ],
            'assets' => [],
        ]);

        self::assertSame('SKIPPED', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertSame([], $result['writes']);
        self::assertSame('NOT_REQUIRED', $result['requirements']['semantic_delta']['applicability']);
        self::assertTrue($result['requirements']['semantic_delta']['evidence']['subject_satisfied']);
    }

    public function test_video_unresolved_subject_without_semantic_delta_remains_fail_closed(): void
    {
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(['video']),
            static fn (): bool => true,
        );

        $result = $service->execute('capture-video-unresolved', 'capture-video-unresolved:semantic', [
            'content_intent' => ['intent' => 'VIDEO', 'semantic_delta' => ['status' => 'NONE']],
            'subject_resolution' => ['status' => 'ambiguous', 'primary' => null, 'resolved' => []],
            'assets' => [],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['SEMANTIC_SUBJECT_OR_DELTA_REQUIRED'], $result['blockers']);
        self::assertFalse($result['requirements']['semantic_delta']['evidence']['subject_satisfied']);
    }

    public function test_knowledge_delta_without_semantic_delta_remains_strict(): void
    {
        $service = new GovernedCaptureContinuationService(
            $this->createMock(GovernedLifecycle::class),
            static fn (): array => [],
            $this->policies(['knowledge']),
            static fn (): bool => true,
        );

        $result = $service->execute('capture-knowledge-no-delta', 'capture-knowledge-no-delta:semantic', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'semantic_delta' => ['status' => 'NONE']],
            'subject_resolution' => ['status' => 'resolved', 'primary' => ['id' => UuidCodec::newV7(), 'type' => 'variant'], 'resolved' => []],
            'assets' => [],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame(['SEMANTIC_SUBJECT_OR_DELTA_REQUIRED'], $result['blockers']);
    }

    public function test_capture_video_applies_when_category_is_the_only_eligibility_blocker(): void
    {
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'ingest', [
            'canonical_id' => $videoId,
            'metadata' => ['category' => ['primary' => null], 'completeness' => ['publishable' => false, 'blockers' => ['CATEGORY_UNRESOLVED']]],
        ], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:category:video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('submit')->with($proposalId)->willReturn($proposal->transition(ProposalState::SUBMITTED));
        $governance->expects(self::exactly(2))->method('review')->with($proposalId)->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'video', 'operation' => 'ingest', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'submitted', 'entity_type' => 'video', 'operation' => 'ingest', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
        );
        $governance->expects(self::once())->method('approve')->with($proposalId, 'content', 'dependency', self::anything())->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn(['ready' => false, 'reasons' => ['CATEGORY_UNRESOLVED']]);
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (string $id): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'entity_type' => 'video', 'active' => true, 'revision' => 1]],
            $this->policies(['video'], ['video' => 'AUTO_PUBLISH']),
            static fn (string $capability): bool => true,
        );

        $result = $service->execute('capture-category', 'capture-category:semantic', [
            'subject_resolution' => ['resolved' => []],
            'interpretation' => [],
            'observations' => [],
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => [
                    'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
                    'payload' => ['canonical_id' => $videoId, 'metadata' => ['category' => ['primary' => null], 'completeness' => ['publishable' => false, 'blockers' => ['CATEGORY_UNRESOLVED']]]],
                ],
            ]],
        ]);

        self::assertSame('APPLIED', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame($videoId, $result['writes'][0]['canonical_readback']['canonical_id']);
        self::assertSame($proposalId, $result['writes'][0]['proposal_id']);
    }

    public function test_text_only_existing_capture_addendum_skips_unchanged_video_child_without_reentering_governance(): void
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::never())->method('createFromArguments');
        $videoId = UuidCodec::newV7();
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (string $id): array => throw new \LogicException('unchanged Video must not apply'),
            $this->policies(['video']),
            static fn (string $capability): bool => true,
        );

        $result = $service->execute('capture-legacy', 'addendum-text', [
            'existing_capture_continuation' => true,
            'continuation_delta_text' => 'Bổ sung văn bản không liên quan đến Video.',
            'subject_resolution' => ['resolved' => []],
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]],
            ]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame('SKIPPED_UNCHANGED', $result['writes'][0]['status']);
        self::assertSame('VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM', $result['blockers'][0]);
    }

    public function test_explicit_video_resume_reenters_original_child_without_new_video_payload(): void
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:resume:video', entityType: 'video');
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $args): bool => ($args['entity_type'] ?? '') === 'video' && ($args['payload']['canonical_id'] ?? '') === $videoId))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposal->id)->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'video', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId]], $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true);

        $result = $service->execute('capture-resume', 'resume-video', [
            'existing_capture_continuation' => true,
            'continuation_delta_text' => 'Bổ sung lý do cần đọc lại Video.',
            'subject_resolution' => ['resolved' => []], 'interpretation' => [], 'observations' => [],
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ], ['resume_children' => ['video']]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_id']);
    }

    public function test_explicit_video_resume_creates_same_video_editorial_update_from_current_delta(): void
    {
        $videoId = UuidCodec::newV7();
        $videos = new class($videoId) implements VideoRepository {
            public Video $video;
            public function __construct(string $id) { $this->video = new Video($id, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Nguồn video', ['source' => ['source_title' => 'Nguồn video', 'external_video_id' => 'dQw4w9WgXcQ'], 'editorial' => ['title' => 'OLD', 'summary' => 'OLD SUMMARY', 'body' => 'OLD BODY', 'why_this_matters' => 'OLD WHY']]); }
            public function findByCanonicalId(string $id): ?Video { return $id === $this->video->canonicalId ? $this->video : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return $platform === $this->video->platform && $externalId === $this->video->externalVideoId ? $this->video : null; }
            public function create(Video $video): Video { return $this->video = $video; }
            public function update(Video $video, int $expectedRevision): Video { return $this->video = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1); }
            public function list(bool $includeRetired = false): array { return [$this->video]; }
        };
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'update', [], 'content', 1, 'dependency', ProposalState::DRAFT, idempotencyKey: 'resume-editorial', targetUuid: $videoId, entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static function (array $args) use ($videoId): bool {
            return ($args['operation'] ?? '') === 'update'
                && ($args['entity_type'] ?? '') === 'video'
                && ($args['subject_id'] ?? '') === $videoId
                && ($args['target_uuid'] ?? '') === $videoId
                && ($args['payload']['metadata']['editorial']['summary'] ?? '') !== 'OLD SUMMARY';
        }))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal->transition(ProposalState::SUBMITTED));
        $governance->method('review')->willReturn(['state' => 'approved', 'entity_type' => 'video', 'operation' => 'update', 'subject_id' => $videoId, 'target_uuid' => $videoId, 'payload' => $proposal->payload, 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $planner = new VideoEditorialResumePlanner($videos, new VideoEditorialGenerator(), new VideoSeoProjection());
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'active' => true, 'revision' => 2]], $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (): bool => true, null, null, null, null, null, null, null, null, $planner);

        $result = $service->execute('capture-resume', 'resume-editorial', [
            'capture_id' => 'capture-resume',
            'existing_capture_continuation' => true,
            'continuation_delta_text' => 'Giải thích giá trị sưu tầm của video này.',
            'subject_resolution' => ['primary' => ['id' => '22222222-2222-4222-8222-222222222222', 'type' => 'variant', 'name' => 'Odo 36/8']],
            'retrieval' => ['selected_claims' => [['id' => 'claim-1', 'revision' => 4]]],
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ], ['resume_children' => ['video']]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_id']);
    }

    public function test_matching_persisted_fingerprint_with_stale_canonical_editorial_retries_update_same_video(): void
    {
        $videoId = UuidCodec::newV7();
        $relationTarget = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $repository = new class($videoId, $relationTarget, $evidenceId) implements VideoRepository {
            public Video $video;

            public function __construct(string $id, string $relationTarget, string $evidenceId)
            {
                $this->video = new Video($id, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Nguồn video', [
                    'source' => ['external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'source_title' => 'Nguồn video'],
                    'editorial_input_fingerprint' => 'placeholder',
                    'editorial' => ['title' => 'Video tham chiếu NHK', 'summary' => 'OLD SUMMARY', 'body' => 'OLD BODY', 'why_this_matters' => 'OLD WHY'],
                    'seo' => ['title' => 'OLD SEO', 'description' => 'OLD SEO DESCRIPTION'],
                    'semantic_attachments' => [['predicate' => 'about', 'target_type' => 'variant', 'target_uuid' => $relationTarget, 'evidence_refs' => [['evidence_id' => $evidenceId]]]],
                ], null, true, 3);
            }

            public function findByCanonicalId(string $id): ?Video { return $id === $this->video->canonicalId ? $this->video : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return $platform === $this->video->platform && $externalId === $this->video->externalVideoId ? $this->video : null; }
            public function create(Video $video): Video { return $this->video = $video; }
            public function update(Video $video, int $expectedRevision): Video
            {
                if ($this->video->revision !== $expectedRevision) throw new \RuntimeException('Video revision conflict.');
                return $this->video = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1);
            }
            public function list(bool $includeRetired = false): array { return [$this->video]; }
            public function replaceMetadata(array $metadata): void { $this->video = new Video($this->video->canonicalId, $this->video->platform, $this->video->externalVideoId, $this->video->canonicalUrl, $this->video->title, $metadata, $this->video->thumbnailMediaId, $this->video->active, $this->video->revision); }
        };
        $context = [
            'continuation_delta_text' => '',
            'subject_resolution' => ['primary' => ['id' => '22222222-2222-4222-8222-222222222222', 'type' => 'variant', 'name' => 'Junghans W64']],
            'retrieval' => ['selected_claims' => []],
        ];
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $first = $planner->plan(['payload' => ['canonical_id' => $videoId]], $context);
        $stale = $first['payload']['metadata'];
        $stale['editorial']['title'] = 'Video tham chiếu NHK';
        $repository->replaceMetadata($stale);

        $createdEntityTypes = [];
        $createdProposal = null;
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->method('createFromArguments')->willReturnCallback(function (array $arguments) use (&$createdEntityTypes, &$createdProposal, $videoId): Proposal {
            $createdEntityTypes[] = $arguments['entity_type'] ?? '';
            $createdProposal = new Proposal(
                UuidCodec::newV7(),
                $videoId,
                'update',
                $arguments['payload'],
                'content',
                (int) $arguments['expected_revision'],
                'dependency',
                ProposalState::DRAFT,
                idempotencyKey: (string) $arguments['idempotency_key'],
                targetUuid: $videoId,
                entityType: 'video',
            );
            return $createdProposal;
        });
        $governance->method('submit')->willReturnCallback(static function (string $id) use (&$createdProposal): Proposal {
            return $createdProposal->transition(ProposalState::SUBMITTED);
        });
        $governance->method('review')->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'video', 'operation' => 'update', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
            ['state' => 'submitted', 'entity_type' => 'video', 'operation' => 'update', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'],
        );
        $governance->method('approve')->willReturnCallback(static function (string $id, string $content, string $dependency, string $actor) use (&$createdProposal): Proposal {
            return $createdProposal->transition(ProposalState::APPROVED, $actor);
        });
        $governance->method('eligibility')->willReturn(['ready' => true]);

        $apply = static function (string $proposalId) use (&$createdProposal, $repository): array {
            $payload = $createdProposal->payload;
            $updated = (new VideoService($repository))->update(
                $payload['canonical_id'],
                $payload['title'],
                $payload['metadata'],
                $payload['thumbnail_media_id'] ?? null,
                $createdProposal->expectedRevision ?? 0,
            );
            return [
                'canonical_id' => $updated->canonicalId,
                'canonical_readback' => [
                    'canonical_id' => $updated->canonicalId,
                    'entity_type' => 'video',
                    'active' => $updated->active,
                    'revision' => $updated->revision,
                    'title' => $updated->metadata['editorial']['title'] ?? '',
                    'seo_projection' => $updated->metadata['seo_projection'] ?? [],
                ],
            ];
        };
        $service = new GovernedCaptureContinuationService(
            $governance,
            $apply,
            $this->policies(['video'], ['video' => 'AUTO_PUBLISH']),
            static fn (string $capability): bool => true,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $planner,
        );

        $result = $service->execute('capture-resume', 'resume-editorial', $context + [
            'capture_id' => 'capture-resume',
            'existing_capture_continuation' => true,
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ], ['resume_children' => ['video']]);

        $updated = $repository->findByCanonicalId($videoId);
        self::assertSame('APPLIED', $result['status']);
        self::assertSame(['video'], $createdEntityTypes);
        self::assertSame($videoId, $result['writes'][0]['canonical_id']);
        self::assertSame('APPLIED', $result['video_children'][0]['status']);
        self::assertSame($videoId, $result['video_children'][0]['canonical_id']);
        self::assertNotSame('REUSE_EDITORIAL', $result['video_children'][0]['reason'] ?? null);
        self::assertSame($videoId, $updated?->canonicalId);
        self::assertNotSame('Video tham chiếu NHK', $updated?->metadata['editorial']['title']);
        self::assertSame($updated?->metadata['editorial']['title'], $updated?->title);
        self::assertSame($updated?->metadata['editorial']['title'], (new VideoSearchDocument(new InMemoryAuthorityRepository()))->title($updated));
        self::assertSame($updated?->metadata['editorial']['title'], $updated?->metadata['seo_projection']['title']);
        self::assertSame($updated?->metadata['editorial']['title'], $updated?->metadata['seo_projection']['open_graph']['title']);
        self::assertSame($updated?->metadata['editorial']['title'], $updated?->metadata['seo_projection']['video_object']['name']);
        self::assertSame($relationTarget, $updated?->metadata['semantic_attachments'][0]['target_uuid']);
    }

    public function test_stale_persisted_video_proposal_revision_is_rebuilt_from_current_resume_plan(): void
    {
        $videoId = UuidCodec::newV7();
        $repository = new class($videoId) implements VideoRepository {
            public Video $video;

            public function __construct(string $id)
            {
                $this->video = new Video($id, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Nguồn video', [
                    'source' => ['external_video_id' => 'dQw4w9WgXcQ', 'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'source_title' => 'Nguồn video'],
                    'editorial_input_fingerprint' => 'placeholder',
                    'editorial' => ['title' => 'Video tham chiếu NHK', 'summary' => 'OLD SUMMARY', 'body' => 'OLD BODY', 'why_this_matters' => 'OLD WHY'],
                ], null, true, 5);
            }

            public function findByCanonicalId(string $id): ?Video { return $id === $this->video->canonicalId ? $this->video : null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return $platform === $this->video->platform && $externalId === $this->video->externalVideoId ? $this->video : null; }
            public function create(Video $video): Video { return $this->video = $video; }
            public function update(Video $video, int $expectedRevision): Video
            {
                if ($this->video->revision !== $expectedRevision) throw new \RuntimeException('Video revision conflict.');
                return $this->video = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1);
            }
            public function list(bool $includeRetired = false): array { return [$this->video]; }
            public function replaceMetadata(array $metadata): void { $this->video = new Video($this->video->canonicalId, $this->video->platform, $this->video->externalVideoId, $this->video->canonicalUrl, $this->video->title, $metadata, $this->video->thumbnailMediaId, $this->video->active, $this->video->revision); }
            public function replaceTitle(string $title): void { $this->video = new Video($this->video->canonicalId, $this->video->platform, $this->video->externalVideoId, $this->video->canonicalUrl, $title, $this->video->metadata, $this->video->thumbnailMediaId, $this->video->active, $this->video->revision); }
        };
        $context = [
            'capture_id' => 'capture-resume',
            'continuation_delta_text' => '',
            'subject_resolution' => ['primary' => ['id' => '22222222-2222-4222-8222-222222222222', 'type' => 'variant', 'name' => 'Junghans W64']],
            'retrieval' => ['selected_claims' => []],
        ];
        $planner = new VideoEditorialResumePlanner($repository, new VideoEditorialGenerator(), new VideoSeoProjection());
        $currentPlan = $planner->plan(['payload' => ['canonical_id' => $videoId]], $context);
        $repository->replaceMetadata($currentPlan['payload']['metadata']);
        $repository->replaceTitle('Video tham chiếu NHK');
        $currentPlan = $planner->plan(['payload' => ['canonical_id' => $videoId]], $context);
        self::assertSame(5, $currentPlan['expected_revision']);

        $staleProposal = new Proposal(UuidCodec::newV7(), $videoId, 'update', $currentPlan['payload'], 'content', 1, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:capture-resume:video-editorial:' . $currentPlan['fingerprint'], targetUuid: $videoId, entityType: 'video');
        $freshProposal = null;
        $createdExpectedRevision = null;
        $governance = new class($staleProposal, $freshProposal, $createdExpectedRevision, $videoId) implements GovernedLifecycle {
            public function __construct(private Proposal $stale, private ?Proposal &$fresh, private ?int &$createdRevision, private string $videoId) {}
            public function findByIdempotencyKey(string $key): ?Proposal { return $key === $this->stale->idempotencyKey ? $this->stale : null; }
            public function createFromArguments(array $arguments): Proposal
            {
                $this->createdRevision = $arguments['expected_revision'] ?? null;
                if (($arguments['entity_type'] ?? '') !== 'video' || ($arguments['operation'] ?? '') !== 'update' || ($arguments['target_uuid'] ?? '') !== $this->videoId || ($arguments['expected_revision'] ?? null) !== 5) throw new \RuntimeException('CURRENT_VIDEO_REVISION_NOT_PROPAGATED');
                return $this->fresh = new Proposal(UuidCodec::newV7(), $this->videoId, 'update', $arguments['payload'], 'content', (int) $arguments['expected_revision'], 'dependency', ProposalState::DRAFT, idempotencyKey: (string) $arguments['idempotency_key'], targetUuid: $this->videoId, entityType: 'video');
            }
            public function submit(string $id): Proposal { return $this->fresh = $this->fresh?->transition(ProposalState::SUBMITTED) ?? throw new \RuntimeException('FRESH_VIDEO_PROPOSAL_MISSING'); }
            public function review(string $id): array { return ['state' => $this->fresh?->state->value ?? 'draft', 'entity_type' => 'video', 'operation' => 'update', 'subject_id' => $this->videoId, 'target_uuid' => $this->videoId, 'expected_revision' => $this->fresh?->expectedRevision, 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']; }
            public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->fresh = $this->fresh?->transition(ProposalState::APPROVED, $actor) ?? throw new \RuntimeException('FRESH_VIDEO_PROPOSAL_MISSING'); }
            public function eligibility(string $id): array { return ['ready' => true]; }
        };
        $apply = static function (string $proposalId) use (&$freshProposal, $repository): array {
            $updated = (new VideoService($repository))->update($freshProposal->payload['canonical_id'], $freshProposal->payload['title'], $freshProposal->payload['metadata'], null, $freshProposal->expectedRevision ?? 0);
            return ['canonical_id' => $updated->canonicalId, 'canonical_readback' => ['canonical_id' => $updated->canonicalId, 'active' => $updated->active, 'revision' => $updated->revision, 'title' => $updated->title]];
        };
        $service = new GovernedCaptureContinuationService($governance, $apply, $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (): bool => true, null, null, null, null, null, null, null, null, $planner);

        $result = $service->execute('capture-resume', 'resume-editorial', $context + [
            'capture_id' => 'capture-resume',
            'existing_capture_continuation' => true,
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ], ['resume_children' => ['video']]);

        self::assertSame(5, $createdExpectedRevision);
        self::assertSame(5, $freshProposal?->expectedRevision);
        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_id']);
        self::assertSame($videoId, $repository->findByCanonicalId($videoId)?->canonicalId);
        self::assertSame(6, $repository->findByCanonicalId($videoId)?->revision);
    }

    public function test_video_candidate_mapper_preserves_expected_revision_for_governance_update(): void
    {
        $videoId = UuidCodec::newV7();
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $plans = new \ReflectionMethod($service, 'plans');
        $plans->setAccessible(true);

        $planned = $plans->invoke($service, 'capture-video-update', 'continuation', [
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => [
                    'operation' => 'update',
                    'entity_type' => 'video',
                    'subject_id' => $videoId,
                    'target_uuid' => $videoId,
                    'expected_revision' => 5,
                    'payload' => ['canonical_id' => $videoId, 'title' => 'W64'],
                ],
            ]],
        ], true);

        self::assertSame('update', $planned[0]['operation']);
        self::assertSame(5, $planned[0]['expected_revision']);
    }

    public function test_invalid_hydrated_video_subject_uses_governed_replacement_boundary(): void
    {
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, 'video', 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'legacy-video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn(['state' => 'approved', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => 'video', 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $repair = $this->createMock(VideoProposalReconciliationPort::class);
        $repair->expects(self::once())->method('reconcile')->with($proposalId)->willReturn(['status' => 'REBUILT_AND_APPLIED', 'replaced_proposal_id' => UuidCodec::newV7(), 'canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'active' => true]]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => [], $this->policies(['video']), static fn (string $capability): bool => true, null, null, $repair);

        $result = $service->execute('capture-legacy', 'legacy-video', [
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($videoId, $result['writes'][0]['canonical_readback']['canonical_id']);
        self::assertSame('REBUILT_AND_APPLIED', $result['writes'][0]['repair']['status']);
    }

    public function test_approved_subject_bound_video_with_stale_empty_attachment_reconciles_before_blocking(): void
    {
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'ingest', [
            'canonical_id' => $videoId,
            'metadata' => [
                'source' => ['platform' => 'youtube', 'external_video_id' => 's53MqUypbKE'],
                'semantic_attachments' => [],
                'subject_resolution_packet' => ['type' => 'classification', 'id' => UuidCodec::newV7()],
            ],
        ], 'historical-content', null, 'historical-dependency', ProposalState::APPROVED, idempotencyKey: 'capture:video', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'approved', 'entity_type' => 'video', 'operation' => 'ingest',
            'payload' => $proposal->payload, 'content_fingerprint' => $proposal->contentFingerprint,
            'dependency_fingerprint' => $proposal->dependencyFingerprint,
        ]);
        $governance->expects(self::once())->method('eligibility')->with($proposalId)->willReturn([
            'ready' => false, 'reasons' => ['NO_SEMANTIC_ATTACHMENT'],
        ]);
        $replacementId = UuidCodec::newV7();
        $repair = $this->createMock(VideoProposalReconciliationPort::class);
        $repair->expects(self::once())->method('reconcile')->with($proposalId)->willReturn([
            'status' => 'REBUILT_AND_APPLIED', 'replaced_proposal_id' => $replacementId,
            'canonical_id' => $videoId,
            'canonical_readback' => ['canonical_id' => $videoId, 'active' => true, 'revision' => 1],
        ]);

        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('stale proposal must not apply'),
            $this->policies(['video'], ['video' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            null,
            null,
            $repair,
        );

        $result = $service->execute('capture-stale-video', 'resume', [
            'assets' => [['kind' => 'video', 'video_proposal' => [
                'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
                'payload' => $proposal->payload,
            ]]],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame($replacementId, $result['writes'][0]['proposal_id']);
        self::assertSame('REBUILT_AND_APPLIED', $result['writes'][0]['repair']['status']);
    }

    public function test_legacy_video_skip_reenters_when_dependency_fingerprint_changes(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'legacy-progress', entityType: 'video');
        $state = ['revision' => 1];
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->method('createFromArguments')->willReturn($proposal);
        $governance->method('review')->willReturn(['state' => 'approved', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => ['canonical_id' => $videoId], 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService($governance, static fn (string $id): array => ['canonical_id' => $videoId, 'canonical_readback' => ['canonical_id' => $videoId, 'active' => true]], $this->policies(['video'], ['video' => 'AUTO_PUBLISH']), static fn (string $capability): bool => true, null, null, null, static function (array $plan) use (&$state): array { return ['source' => ['source-1', $state['revision'], true]]; });
        $context = ['existing_capture_continuation' => true, 'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]]];
        $first = $service->execute('legacy', 'addendum-1', $context);
        self::assertSame('SKIPPED_UNCHANGED', $first['writes'][0]['status']);
        $context['prior_diagnostics'] = ['semantic_write_back' => ['video_children' => $first['video_children']]];
        $second = $service->execute('legacy', 'addendum-2', $context);
        self::assertSame('SKIPPED_UNCHANGED', $second['writes'][0]['status']);
        $state['revision'] = 2;
        $third = $service->execute('legacy', 'addendum-3', $context);
        self::assertSame('APPLIED', $third['status']);
        self::assertNotSame('SKIPPED_UNCHANGED', $third['writes'][0]['status']);
    }

    public function test_video_child_fingerprint_ignores_timestamps_and_evidence_order_but_tracks_semantic_revisions(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'videoPlanFingerprint');
        $method->setAccessible(true);
        $video = UuidCodec::newV7();
        $plan = ['capture_video_provenance' => ['relation' => ['target_type' => 'variant', 'target_uuid' => $video], 'dependencies' => [['payload' => ['metadata' => ['platform' => 'youtube', 'external_video_id' => 'abc']]]]]];
        $base = ['subject' => ['id' => $video, 'revision' => 3], 'source' => ['source-1', 2, true], 'claim' => ['claim-1', 4, true], 'evidence' => [['e-2', 1, 'source-1', true], ['e-1', 1, 'source-1', true]], 'proposal' => ['p-1', 2, 'approved']];
        $same = $base; $same['evidence'] = array_reverse($same['evidence']); $same['fetched_at'] = 'different';
        self::assertSame($method->invoke($service, $plan, ['video_dependency_fingerprint' => $base]), $method->invoke($service, $plan, ['video_dependency_fingerprint' => $same]));
        $changed = $base; $changed['evidence'][0][1] = 2;
        self::assertNotSame($method->invoke($service, $plan, ['video_dependency_fingerprint' => $base]), $method->invoke($service, $plan, ['video_dependency_fingerprint' => $changed]));
        $changedSubject = $base; $changedSubject['subject']['revision'] = 4;
        self::assertNotSame($method->invoke($service, $plan, ['video_dependency_fingerprint' => $base]), $method->invoke($service, $plan, ['video_dependency_fingerprint' => $changedSubject]));
    }

    public function test_budget_stops_before_next_expensive_phase(): void
    {
        $now = 100.0;
        $budget = new CaptureOrchestrationBudget(10, static function () use (&$now): float { return $now; });
        $budget->begin(); $now = 110.0;
        $this->expectException(\NHK\Core\Application\Capture\CaptureOrchestrationBudgetExceeded::class);
        $budget->check('NEXT_PHASE');
    }

    public function test_typed_binding_failure_is_blocked_even_when_exception_wording_changes(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'classifiedFailure');
        $method->setAccessible(true);
        $result = $method->invoke($service, ['entity_type' => 'video'], new \NHK\Core\Governance\Exception\ProposalSubjectBindingInvalid('changed diagnostic wording'));
        self::assertSame('SYSTEM_BLOCKED', $result['status']);
        self::assertSame('changed diagnostic wording', $result['error']);
    }

    public function test_brand_knowledge_scope_is_derived_from_locked_subject_not_interpreter_default(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'plans');
        $method->setAccessible(true);
        $brand = UuidCodec::newV7();
        $plans = $method->invoke($service, 'capture-brand', 'continuation', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $brand, 'type' => 'brand']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Hermle được thành lập năm 1922.', 'scope' => 'variant', 'facet' => 'identity']]],
        ]);

        self::assertCount(2, $plans);
        self::assertSame('brand', $plans[0]['payload']['provenance']['metadata']['scope']);
    }

    public function test_image_article_without_explicit_semantic_delta_does_not_plan_governance_or_wp_post_relation(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(['relation']), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'plans');
        $method->setAccessible(true);
        $variant = UuidCodec::newV7();

        $plans = $method->invoke($service, 'capture-image-article', 'retry', [
            'content_intent' => ['intent' => 'IMAGE_ARTICLE', 'semantic_delta' => ['status' => 'NONE']],
            'article_id' => 575,
            'article_endpoint_key' => '1:575',
            'subject_resolution' => ['primary' => ['id' => $variant, 'type' => 'variant'], 'resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'candidate from old retry', 'scope' => 'variant']]],
        ]);

        self::assertCount(1, $plans);
        self::assertSame('relation_create', $plans[0]['operation']);
        self::assertSame($variant, $plans[0]['payload']['target_uuid']);
    }

    public function test_knowledge_plan_emits_governed_about_relation_to_locked_subject(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'plans');
        $method->setAccessible(true);
        $brand = UuidCodec::newV7();
        $plans = $method->invoke($service, 'capture-brand', 'continuation', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $brand, 'type' => 'brand']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Hermle được thành lập năm 1922.', 'facet' => 'identity']]],
        ]);

        self::assertSame(['knowledge', 'relation'], array_column($plans, 'entity_type'));
        self::assertSame('about', $plans[1]['payload']['predicate']);
        self::assertSame($brand, $plans[1]['payload']['target_uuid']);
        self::assertSame('knowledge', $plans[1]['payload']['source_type']);
    }

    public function test_active_knowledge_about_edge_is_reused_without_duplicate_governance_proposal(): void
    {
        $variant = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $variant, 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: 'capture:knowledge', entityType: 'knowledge');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->with(self::callback(static fn (array $plan): bool => ($plan['entity_type'] ?? '') === 'knowledge'))->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->method('review')->willReturnOnConsecutiveCalls(['state' => 'draft', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency'], ['state' => 'submitted', 'entity_type' => 'knowledge', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, 'system'));
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => ['canonical_id' => 'claim-1', 'canonical_readback' => ['canonical_id' => 'claim-1', 'active' => true, 'revision' => 1]],
            $this->policies(['knowledge', 'relation'], ['knowledge' => 'AUTO_PUBLISH', 'relation' => 'AUTO_PUBLISH']),
            static fn (): bool => true,
            relationState: static fn (): array => ['status' => 'ACTIVE', 'canonical_id' => 'edge-1', 'revision' => 2, 'active' => true],
        );

        $result = $service->execute('capture-1', 'capture:knowledge', [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
            'subject_resolution' => ['resolved' => [['id' => $variant, 'type' => 'variant']]],
            'interpretation' => ['user_claim_candidates' => [['text' => 'Cấu hình 10 côn.', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]],
        ]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame('REUSED_VERIFIED', $result['writes'][1]['status']);
        self::assertSame('edge-1', $result['writes'][1]['canonical_id']);
    }

    public function test_rate_limit_is_retryable_and_has_stable_external_failure_code(): void
    {
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(), static fn (): bool => true);
        $method = new \ReflectionMethod($service, 'classifiedFailure');
        $method->setAccessible(true);
        $result = $method->invoke($service, ['entity_type' => 'knowledge'], new \RuntimeException('HTTP 429 Too Many Requests; Retry-After: 10'));

        self::assertSame('FAILED_RETRYABLE', $result['status']);
        self::assertSame(['EXTERNAL_RATE_LIMIT'], $result['blockers']);
    }

    public function test_pending_video_governance_exposes_persisted_proposal_and_binding_fingerprints(): void
    {
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'ingest', [
            'canonical_id' => $videoId,
            'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'mT4GmDAuWYY']],
        ], 'content-video-fingerprint', null, 'dependency-video-fingerprint', ProposalState::DRAFT, idempotencyKey: 'capture:video:pending', entityType: 'video');
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('createFromArguments')->willReturn($proposal);
        $governance->expects(self::exactly(2))->method('review')->with($proposalId)->willReturnOnConsecutiveCalls(
            ['state' => 'draft', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => $proposal->payload, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint],
            ['state' => 'submitted', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => $proposal->payload, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint],
        );
        $governance->expects(self::once())->method('submit')->with($proposalId)->willReturn($proposal->transition(ProposalState::SUBMITTED, 'test'));
        $governance->expects(self::never())->method('approve');
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => [], $this->policies(['video'], ['video' => 'REVIEW_REQUIRED']), static fn (): bool => true);

        $result = $service->execute('01a0b506-9e0c-763e-a796-a69e2d6df497', 'capture:video:pending', [
            'content_intent' => ['intent' => 'VIDEO'],
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => $proposal->payload]]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertTrue($result['governance']['approval_required']);
        self::assertSame([$proposalId], $result['governance']['proposal_ids']);
        self::assertNotSame($videoId, $result['governance']['proposal_ids'][0]);
        self::assertSame('content-video-fingerprint', $result['governance']['proposals'][0]['content_fingerprint']);
        self::assertSame('dependency-video-fingerprint', $result['governance']['proposals'][0]['dependency_fingerprint']);

        $capture = new \NHK\Core\Domain\Capture\CaptureRecord(
            '01a0b506-9e0c-763e-a796-a69e2d6df497',
            'capture:video:pending',
            hash('sha256', 'capture:video:pending'),
            'SEMANTICS_RECONCILED',
            'REVIEW_REQUIRED',
            diagnostics: ['semantic_write_back' => $result],
        );
        self::assertSame([$proposalId], $capture->toArray()['governance']['proposal_ids']);
    }

    public function test_existing_capture_retry_reuses_pending_video_proposal_without_reissuing_staging_scope(): void
    {
        $captureId = '01a0b506-9e0c-763e-a796-a69e2d6df497';
        $videoId = '01a0b506-a292-7cbe-a356-51fc78fec069';
        $proposalId = UuidCodec::newV7();
        $subjectId = '4cd79149-5cad-4427-aab4-0cea3aebe8c1';
        $payload = [
            'canonical_id' => $videoId,
            'expected_revision' => 0,
            'metadata' => [
                'source' => [
                    'platform' => 'youtube',
                    'external_video_id' => 'mT4GmDAuWYY',
                    'canonical_source_url' => 'https://www.youtube.com/watch?v=mT4GmDAuWYY',
                ],
                'subject_resolution_packet' => ['type' => 'variant', 'id' => $subjectId, 'revision' => 1],
            ],
        ];
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'submitted',
            'entity_type' => 'video',
            'operation' => 'ingest',
            'subject_id' => $videoId,
            'target_uuid' => $videoId,
            'payload' => $payload,
            'content_fingerprint' => 'persisted-content-fingerprint',
            'dependency_fingerprint' => 'persisted-dependency-fingerprint',
        ]);
        $governance->expects(self::never())->method('createFromArguments');
        $governance->expects(self::never())->method('submit');
        $governance->expects(self::never())->method('approve');
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('pending Proposal must not apply'),
            $this->policies(['video'], ['video' => 'REVIEW_REQUIRED']),
            static fn (): bool => true,
            videoScopeIssuer: static fn (): array => throw new \LogicException('staging scope must not be reissued'),
        );

        $result = $service->execute($captureId, 'capture:video:retry', [
            'existing_capture_continuation' => true,
            'content_intent' => ['intent' => 'VIDEO'],
            'prior_diagnostics' => [
                'semantic_write_back' => [
                    'writes' => [[
                        'proposal_id' => $proposalId,
                        'entity_type' => 'video',
                        'operation' => 'ingest',
                        'status' => 'REVIEW_REQUIRED',
                        'target_uuid' => $videoId,
                        'external_video' => ['platform' => 'youtube', 'external_video_id' => 'mT4GmDAuWYY'],
                    ]],
                ],
            ],
            'assets' => [['kind' => 'video', 'video_proposal' => [
                'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'payload' => $payload,
            ]]],
        ], ['resume_children' => ['video']]);

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame([$proposalId], $result['governance']['proposal_ids']);
        self::assertSame('persisted-content-fingerprint', $result['governance']['proposals'][0]['content_fingerprint']);
        self::assertSame('persisted-dependency-fingerprint', $result['governance']['proposals'][0]['dependency_fingerprint']);
        self::assertSame(['GOVERNANCE_APPROVAL_REQUIRED'], $result['blockers']);
    }

    public function test_existing_capture_retry_uses_canonical_pending_lookup_when_receipt_is_incomplete(): void
    {
        $captureId = '01a0b506-9e0c-763e-a796-a69e2d6df497';
        $videoId = '01a0b506-a292-7cbe-a356-51fc78fec069';
        $proposalId = UuidCodec::newV7();
        $proposal = new Proposal($proposalId, $videoId, 'ingest', [
            'canonical_id' => $videoId,
            'expected_revision' => 0,
            'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'mT4GmDAuWYY']],
        ], 'persisted-content', null, 'persisted-dependency', ProposalState::SUBMITTED, idempotencyKey: $captureId . ':video', entityType: 'video');
        $lookup = new class($proposal) implements PendingVideoProposalLookup {
            public function __construct(private Proposal $proposal) {}
            public function findPendingVideoProposals(array $binding): array
            {
                return $binding['idempotency_key'] === $this->proposal->idempotencyKey && $binding['video_id'] === $this->proposal->payload['canonical_id'] ? [$this->proposal] : [];
            }
        };
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'submitted', 'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
            'payload' => $proposal->payload, 'content_fingerprint' => $proposal->contentFingerprint,
            'dependency_fingerprint' => $proposal->dependencyFingerprint,
        ]);
        $governance->expects(self::never())->method('createFromArguments');
        $service = new GovernedCaptureContinuationService(
            $governance, static fn (): array => throw new \LogicException('must not apply'), $this->policies(['video'], ['video' => 'REVIEW_REQUIRED']), static fn (): bool => true,
            pendingVideoProposals: $lookup,
        );
        $result = $service->execute($captureId, 'retry', [
            'existing_capture_continuation' => true, 'content_intent' => ['intent' => 'VIDEO'],
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => $proposal->payload]]],
        ], ['resume_children' => ['video']]);
        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame([$proposalId], $result['governance']['proposal_ids']);
        self::assertSame('persisted-content', $result['governance']['proposals'][0]['content_fingerprint']);
        self::assertSame('persisted-dependency', $result['governance']['proposals'][0]['dependency_fingerprint']);
    }

    public function test_canonical_lookup_does_not_reuse_different_child_or_terminal_proposal(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'other-capture:video', entityType: 'video');
        $lookup = new class($proposal) implements PendingVideoProposalLookup {
            public function __construct(private Proposal $proposal) {}
            public function findPendingVideoProposals(array $binding): array { return $binding['idempotency_key'] === $this->proposal->idempotencyKey && in_array($this->proposal->state, [ProposalState::DRAFT, ProposalState::SUBMITTED], true) ? [$this->proposal] : []; }
        };
        $issuerCalled = false;
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(['video']), static fn (): bool => true, pendingVideoProposals: $lookup, videoScopeIssuer: static function () use (&$issuerCalled): array { $issuerCalled = true; return ['capture_fingerprint' => 'scope']; });
        $result = $service->execute('capture-new', 'retry', ['existing_capture_continuation' => true, 'content_intent' => ['intent' => 'VIDEO'], 'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]]], ['resume_children' => ['video']]);
        self::assertTrue($issuerCalled);
        self::assertNotSame([$proposal->id], $result['governance']['proposal_ids']);
    }

    public function test_ambiguous_canonical_pending_lookup_fails_closed(): void
    {
        $videoId = UuidCodec::newV7();
        $proposals = [
            new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'a', null, 'b', ProposalState::SUBMITTED, idempotencyKey: 'capture:video', entityType: 'video'),
            new Proposal(UuidCodec::newV7(), $videoId, 'ingest', ['canonical_id' => $videoId], 'c', null, 'd', ProposalState::SUBMITTED, idempotencyKey: 'capture:video', entityType: 'video'),
        ];
        $lookup = new class($proposals) implements PendingVideoProposalLookup {
            public function __construct(private array $proposals) {}
            public function findPendingVideoProposals(array $binding): array { return $this->proposals; }
        };
        $service = new GovernedCaptureContinuationService($this->createMock(GovernedLifecycle::class), static fn (): array => [], $this->policies(['video']), static fn (): bool => true, pendingVideoProposals: $lookup);
        $result = $service->execute('capture', 'retry', ['existing_capture_continuation' => true, 'content_intent' => ['intent' => 'VIDEO'], 'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]]], ['resume_children' => ['video']]);
        self::assertSame('SYSTEM_BLOCKED', $result['status']);
        self::assertContains('AMBIGUOUS_PENDING_VIDEO_PROPOSAL', $result['blockers']);
    }

    public function test_applied_proposal_replay_uses_persisted_readback_without_reapplying(): void
    {
        $proposalId = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::once())->method('review')->with($proposalId)->willReturn([
            'state' => 'applied', 'entity_type' => 'knowledge', 'operation' => 'ingest', 'subject_id' => $claimId, 'target_uuid' => $claimId, 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency',
            'canonical_readback' => ['canonical_id' => $claimId, 'entity_type' => 'knowledge', 'active' => true, 'revision' => 2],
        ]);
        $service = new GovernedCaptureContinuationService($governance, static fn (): array => throw new \LogicException('APPLIED proposal must not be applied again'), $this->policies(), static fn (): bool => true);

        $result = $service->execute('capture-1', 'replay', [], ['proposal_ids' => [$proposalId]]);

        self::assertSame('APPLIED', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertTrue($result['writes'][0]['idempotent']);
    }

    private function policies(array $types = ['knowledge'], array $stored = []): GovernanceAutomationPolicyResolver
    {
        return new GovernanceAutomationPolicyResolver($types, new class($stored) implements AutomationPolicyStorage {
            public function __construct(private array $stored) {}
            public function read(): array { return $this->stored; }
            public function write(array $policies): void {}
        });
    }
}
