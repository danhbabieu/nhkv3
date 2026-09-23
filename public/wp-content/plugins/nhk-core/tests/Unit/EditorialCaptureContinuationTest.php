<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{EditorialCaptureContinuationService, EditorialCaptureCoordinator};
use NHK\Core\Application\Mcp\{McpDocumentationRegistry, McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Contracts\Capture\{CaptureAddendumRepository, CaptureRepository};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Capture\{CaptureAddendumRecord, CaptureRecord, CaptureStage};
use NHK\Core\Domain\Video\VideoRelationEvidenceRequired;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Governance\Exception\ProposalSubjectBindingInvalid;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureContinuationTest extends TestCase
{
    public function test_retry_resumes_original_capture_key_without_creating_addendum_or_replaying_physical_phases(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-original-retry',
            hash('sha256', 'original-retry'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'FAILED_RETRYABLE',
            342,
            'state-342',
            [],
            [
                'raw_input' => 'Ghi chú ban đầu.',
                'subject_hints' => ['Odo 30'],
                'title' => 'Bài 342',
                'excerpt' => 'Tóm tắt.',
                'metadata' => ['provenance_packets' => ['sources' => [['stable_key' => 'nhk:source:test']]]],
                'content_intent' => ['intent' => 'TEXT_ARTICLE', 'article_required' => true],
                'documentation_checkpoint' => ['manifest_hash' => str_repeat('a', 64), 'documentation_version' => str_repeat('b', 64)],
                'original_request' => ['publish' => false],
            ],
            ['failure' => ['code' => 'CAPTURE_GOVERNANCE_FAILED', 'classification' => 'FAILED_RETRYABLE']],
            ['SEMANTICS_RECONCILED' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE']],
        );
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $first = $service->retry([
            'capture_id' => $capture->captureId,
            'idempotency_key' => $capture->idempotencyKey,
            'resume_mode' => 'RETRY',
        ]);
        $second = $service->retry([
            'capture_id' => $capture->captureId,
            'idempotency_key' => $capture->idempotencyKey,
            'resume_mode' => 'RETRY',
        ]);

        self::assertSame('RETRY', $first['retry']['mode']);
        self::assertSame($capture->captureId, $first['capture']['capture_id']);
        self::assertSame($capture->idempotencyKey, $first['capture']['idempotency_key']);
        self::assertSame($capture->captureId, $second['capture']['capture_id']);
        self::assertCount(0, $addenda->records);
        self::assertArrayNotHasKey('physical', $events);
        self::assertArrayNotHasKey('draft', $events);
        self::assertSame(1, $events['semantic']);
        self::assertSame(['sources' => [['stable_key' => 'nhk:source:test']]], $events['provenance_packets']);
        self::assertTrue($events['existing_capture_continuation']);
        self::assertSame($capture->idempotencyKey, $events['continuation_idempotency_key']);
    }

    public function test_retry_requires_exact_original_key_and_rejects_new_editorial_payload(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-retry-key',
            hash('sha256', 'retry-key'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'FAILED_RETRYABLE',
            null,
            null,
            [],
            ['raw_input' => 'Nội dung gốc.', 'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'article_required' => false]],
            ['failure' => ['code' => 'CAPTURE_GOVERNANCE_FAILED']],
            [],
        );
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $wrongKey = $service->retry(['capture_id' => $capture->captureId, 'idempotency_key' => 'different-key', 'resume_mode' => 'RETRY']);
        $payload = $service->retry(['capture_id' => $capture->captureId, 'idempotency_key' => $capture->idempotencyKey, 'resume_mode' => 'RETRY', 'text' => 'Không được đổi payload.']);

        self::assertSame('FAILED', $wrongKey['retry']['status']);
        self::assertSame('CAPTURE_RETRY_IDEMPOTENCY_KEY_MISMATCH', $wrongKey['retry']['code']);
        self::assertSame('FAILED', $payload['retry']['status']);
        self::assertSame('CAPTURE_RETRY_PAYLOAD_NOT_ALLOWED', $payload['retry']['code']);
        self::assertCount(0, $addenda->records);
        self::assertArrayNotHasKey('semantic', $events);
    }

    public function test_partial_completion_with_video_resume_hint_allows_retry_even_when_capture_status_is_review_required(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->resumableVideoCapture('REVIEW_REQUIRED', 'PARTIAL');
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $result = $service->retry([
            'capture_id' => $capture->captureId,
            'idempotency_key' => $capture->idempotencyKey,
            'resume_mode' => 'RETRY',
        ]);

        self::assertNotSame('CAPTURE_RETRY_NOT_ALLOWED', $result['retry']['code']);
        self::assertSame(1, $events['semantic']);
        self::assertCount(0, $addenda->records);
        self::assertTrue($events['existing_capture_continuation']);
    }

    public function test_review_required_completion_with_incomplete_video_owner_allows_retry(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->resumableVideoCapture('REVIEW_REQUIRED', 'REVIEW_REQUIRED');
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $result = $service->retry([
            'capture_id' => $capture->captureId,
            'idempotency_key' => $capture->idempotencyKey,
            'resume_mode' => 'RETRY',
            'resume_children' => ['video'],
        ]);

        self::assertNotSame('CAPTURE_RETRY_NOT_ALLOWED', $result['retry']['code']);
        self::assertSame(1, $events['semantic']);
        self::assertCount(0, $addenda->records);
    }

    public function test_historical_applied_video_retry_rechecks_public_readiness_without_semantic_reentry(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $videoId = UuidCodec::newV7();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-historical-video-retry',
            hash('sha256', 'historical-video-retry'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'PARTIAL',
            null,
            null,
            [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['payload' => ['canonical_id' => $videoId]]]],
            ['purpose' => 'EDITORIAL', 'raw_input' => 'Video đã tồn tại.', 'content_intent' => ['intent' => 'VIDEO', 'article_required' => false]],
            [
                'completion' => ['status' => 'PARTIAL', 'blockers' => ['PUBLIC_ELIGIBILITY_NOT_VERIFIED', 'CONTENT_NEEDS_REVIEW'], 'missing_required_owners' => [], 'children' => [['owner_type' => 'video', 'owner_id' => $videoId, 'status' => 'PARTIAL', 'complete' => false]]],
                'semantic_write_back' => ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => $videoId, 'revision' => 7], 'writes' => []],
            ],
            ['SEMANTICS_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'APPLIED']],
        );
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events, null, static function (array $context) use (&$events, $videoId): array {
            $events['video_verifier'] = ($events['video_verifier'] ?? 0) + 1;
            $complete = ($events['pass'] ?? false) === true;
            return ['status' => $complete ? 'VERIFIED' : 'PARTIAL', 'items' => [['video_id' => $videoId, 'completion' => ['owner_type' => 'video', 'owner_id' => $videoId, 'status' => $complete ? 'COMPLETE' : 'PARTIAL', 'complete' => $complete, 'canonical_readback' => ['canonical_id' => $videoId], 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'content_state' => 'CONTENT_COMPLETE', 'public_state' => $complete ? 'READY' : 'BLOCKED', 'frontend_state' => $complete ? 'VERIFIED' : 'BLOCKED', 'blockers' => $complete ? [] : ['FRONTEND_READBACK_NOT_VERIFIED']]]], 'blockers' => $complete ? [] : ['FRONTEND_READBACK_NOT_VERIFIED']];
        }));

        $result = $service->retry([
            'capture_id' => $capture->captureId,
            'resume_mode' => 'RETRY',
            'purpose' => 'EDITORIAL',
            'intent' => 'VIDEO',
            'governance' => ['approval_confirmed' => true],
        ]);

        self::assertSame($capture->captureId, $result['capture']['capture_id']);
        self::assertSame('REVIEW_REQUIRED', $result['capture']['status']);
        self::assertContains('FRONTEND_READBACK_NOT_VERIFIED', $result['capture']['diagnostics']['completion']['blockers']);
        self::assertSame(1, $events['video_verifier']);
        self::assertArrayNotHasKey('semantic', $events);
        self::assertArrayNotHasKey('physical', $events);
        self::assertArrayNotHasKey('draft', $events);
        self::assertTrue($result['retry']['eligible']);
        self::assertCount(0, $addenda->records);

        $events['pass'] = true;
        $converged = $service->retry(['capture_id' => $capture->captureId, 'resume_mode' => 'RETRY']);
        self::assertSame('COMPLETE', $converged['capture']['status']);
        self::assertNotContains('CONTENT_NEEDS_REVIEW', $converged['capture']['diagnostics']['completion']['blockers']);
        self::assertNotContains('PUBLIC_ELIGIBILITY_NOT_VERIFIED', $converged['capture']['diagnostics']['completion']['blockers']);
        self::assertSame(2, $events['video_verifier']);
        self::assertFalse($converged['retry']['eligible']);
        self::assertSame('CAPTURE_RETRY_NOT_ALLOWED', $converged['retry']['reason']);
    }

    public function test_video_retry_refreshes_private_hidden_dependencies_from_internal_canonical_readback(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $sourceId = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $source = new Source($sourceId, 'source:private', 'Private source', metadata: ['visibility' => 'PRIVATE']);
        $claim = new KnowledgeClaim($claimId, 'claim:private', 'Verified private claim', provenance: ['metadata' => ['verification_status' => 'PRIVATE']]);
        $evidence = new Evidence($evidenceId, $claimId, $sourceId, excerpt: 'Hidden supporting excerpt', metadata: ['visibility' => 'HIDDEN']);
        $claims = $this->createMock(KnowledgeRepository::class);
        $claims->method('findByCanonicalId')->with($claimId)->willReturn($claim);
        $sources = $this->createMock(SourceRepository::class);
        $sources->method('findByCanonicalId')->with($sourceId)->willReturn($source);
        $evidenceRepository = $this->createMock(EvidenceRepository::class);
        $evidenceRepository->method('findByCanonicalId')->with($evidenceId)->willReturn($evidence);
        $validator = new CanonicalDependencyValidator($claims, $sources, $evidenceRepository);
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-internal-dependency-readback',
            hash('sha256', 'internal-dependency-readback'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'PARTIAL',
            null,
            null,
            [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['payload' => ['canonical_id' => $videoId]]]],
            ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO', 'article_required' => false]],
            [
                'completion' => ['status' => 'PARTIAL', 'blockers' => ['CANONICAL_READBACK_UNVERIFIED'], 'children' => []],
                'semantic_write_back' => ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => $videoId], 'writes' => [
                    ['entity_type' => 'source', 'canonical_id' => $sourceId, 'completion' => ['owner_type' => 'source', 'owner_id' => $sourceId, 'canonical_state' => 'BLOCKED', 'canonical_readback_verified' => false, 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'blockers' => ['CANONICAL_READBACK_UNVERIFIED']]],
                    ['entity_type' => 'knowledge', 'canonical_id' => $claimId, 'completion' => ['owner_type' => 'knowledge', 'owner_id' => $claimId, 'canonical_state' => 'BLOCKED', 'canonical_readback_verified' => false, 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'blockers' => ['CANONICAL_READBACK_UNVERIFIED']]],
                    ['entity_type' => 'evidence', 'canonical_id' => $evidenceId, 'completion' => ['owner_type' => 'evidence', 'owner_id' => $evidenceId, 'canonical_state' => 'BLOCKED', 'canonical_readback_verified' => false, 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'blockers' => ['CANONICAL_READBACK_UNVERIFIED']]],
                ]],
            ],
            [],
        );
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events, null, static fn (array $context): array => ['status' => 'verified', 'items' => [['video_id' => $videoId, 'completion' => ['owner_type' => 'video', 'owner_id' => $videoId, 'canonical_state' => 'COMPLETE', 'canonical_readback_verified' => true, 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'content_state' => 'CONTENT_COMPLETE', 'public_state' => 'READY', 'frontend_state' => 'VERIFIED', 'complete' => true, 'status' => 'COMPLETE', 'blockers' => []]]], 'blockers' => []], $validator));

        $result = $service->retry(['capture_id' => $capture->captureId, 'resume_mode' => 'RETRY']);
        $children = $result['capture']['diagnostics']['completion']['children'];
        $byType = [];
        foreach ($children as $child) $byType[(string) ($child['owner_type'] ?? '')] = $child;

        self::assertSame('COMPLETE', $result['capture']['status']);
        self::assertSame('COMPLETE', $byType['source']['status']);
        self::assertSame('COMPLETE', $byType['knowledge']['status']);
        self::assertSame('COMPLETE', $byType['evidence']['status']);
        self::assertSame('COMPLETE', $byType['video']['status']);
        self::assertNotContains('CANONICAL_READBACK_UNVERIFIED', $result['capture']['diagnostics']['completion']['blockers']);
        self::assertFalse($result['retry']['eligible']);
    }

    public function test_terminal_video_retry_converges_stale_capture_before_returning_not_allowed(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $videoId = UuidCodec::newV7();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-terminal-convergence',
            hash('sha256', 'terminal-convergence'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'PARTIAL',
            null,
            null,
            [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['payload' => ['canonical_id' => $videoId]]]],
            ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO', 'article_required' => false]],
            [
                'completion' => [
                    'status' => 'COMPLETE',
                    'blockers' => ['CANONICAL_READBACK_UNVERIFIED'],
                    'children' => [
                        ['owner_type' => 'source', 'owner_id' => UuidCodec::newV7(), 'status' => 'BLOCKED', 'complete' => false],
                        ['owner_type' => 'knowledge', 'owner_id' => UuidCodec::newV7(), 'status' => 'BLOCKED', 'complete' => false],
                        ['owner_type' => 'evidence', 'owner_id' => UuidCodec::newV7(), 'status' => 'BLOCKED', 'complete' => false],
                        ['owner_type' => 'video', 'owner_id' => $videoId, 'status' => 'COMPLETE', 'complete' => true],
                    ],
                    'missing_required_owners' => [],
                ],
                'resume_hints' => ['resume_children' => ['video']],
                'semantic_write_back' => ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => $videoId], 'writes' => []],
            ],
            ['SEMANTICS_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'APPLIED']],
        );
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events, null, static function (array $context) use ($videoId): array {
            return ['status' => 'VERIFIED', 'items' => [['video_id' => $videoId, 'completion' => [
                'owner_type' => 'video', 'owner_id' => $videoId, 'status' => 'COMPLETE', 'complete' => true,
                'canonical_readback' => ['canonical_id' => $videoId], 'dependency_state' => 'COMPLETE',
                'relation_or_usage_state' => 'COMPLETE', 'content_state' => 'CONTENT_COMPLETE',
                'public_state' => 'READY', 'frontend_state' => 'VERIFIED', 'blockers' => [],
            ]]], 'blockers' => []];
        }));

        $result = $service->retry([
            'capture_id' => $capture->captureId,
            'idempotency_key' => $capture->idempotencyKey,
            'resume_mode' => 'RETRY',
            'resume_children' => ['video'],
        ]);

        self::assertSame('COMPLETE', $result['capture']['status']);
        self::assertSame([], $result['capture']['diagnostics']['completion']['blockers']);
        self::assertSame('CAPTURE_RETRY_NOT_ALLOWED', $result['retry']['code']);
        self::assertSame('COMPLETE', $result['retry']['status']);
        self::assertFalse($result['retry']['eligible']);
        self::assertSame('CAPTURE_RETRY_NOT_ALLOWED', $result['retry']['reason']);
        self::assertArrayNotHasKey('semantic', $events);

        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $captures,
        );
        $projection = $read->captureGet($capture->captureId);
        self::assertSame('COMPLETE', $projection['capture_status']);
        self::assertSame([], $projection['blockers']);
        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED', 'capture_id' => $capture->captureId], $projection['retry']);
    }

    public function test_retry_reconstructs_historical_source_claim_evidence_kinds_and_revisions_from_governance_receipts(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $sourceId = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $videoId = UuidCodec::newV7();
        $source = new Source($sourceId, 'source:historical', 'Private source', metadata: ['visibility' => 'PRIVATE']);
        $claim = new KnowledgeClaim($claimId, 'claim:historical', 'Private claim', provenance: ['metadata' => ['verification_status' => 'PRIVATE']]);
        $evidence = new Evidence($evidenceId, $claimId, $sourceId, excerpt: 'Hidden evidence', metadata: ['visibility' => 'HIDDEN']);
        $claims = $this->createMock(KnowledgeRepository::class);
        $claims->method('findByCanonicalId')->willReturnCallback(static fn (string $id): ?KnowledgeClaim => $id === $claimId ? $claim : null);
        $sources = $this->createMock(SourceRepository::class);
        $sources->method('findByCanonicalId')->willReturnCallback(static fn (string $id): ?Source => $id === $sourceId ? $source : null);
        $evidenceRepository = $this->createMock(EvidenceRepository::class);
        $evidenceRepository->method('findByCanonicalId')->willReturnCallback(static fn (string $id): ?Evidence => $id === $evidenceId ? $evidence : null);
        $validator = new CanonicalDependencyValidator($claims, $sources, $evidenceRepository);
        $writes = [];
        foreach ([['id' => $sourceId, 'phase' => 'VIDEO_SOURCE_GOVERNANCE'], ['id' => $claimId, 'phase' => 'VIDEO_CLAIM_GOVERNANCE'], ['id' => $evidenceId, 'phase' => 'VIDEO_EVIDENCE_GOVERNANCE']] as $dependency) {
            $writes[] = ['entity_type' => 'knowledge', 'canonical_id' => $dependency['id'], 'completion' => [
                'owner_type' => 'knowledge', 'owner_id' => $dependency['id'], 'status' => 'PARTIAL', 'complete' => false, 'canonical_state' => 'BLOCKED',
                'canonical_readback_verified' => false, 'dependency_state' => 'PARTIAL', 'relation_or_usage_state' => 'PARTIAL',
                'blockers' => ['CANONICAL_READBACK_UNVERIFIED'],
            ]];
        }
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'capture-historical-kinds', hash('sha256', 'historical-kinds'), CaptureStage::SEMANTICS_RECONCILED->value, 'PARTIAL', null, null,
            [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['payload' => ['canonical_id' => $videoId]]]],
            ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO', 'article_required' => false]],
            [
                'completion' => ['status' => 'PARTIAL', 'blockers' => ['CANONICAL_READBACK_UNVERIFIED'], 'children' => [], 'missing_required_owners' => []],
                'resume_hints' => ['resume_children' => ['video']],
                'semantic_write_back' => ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => $videoId], 'writes' => $writes],
            ],
            [
                'VIDEO_SOURCE_GOVERNANCE' => ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED', 'canonical_id' => $sourceId, 'revision' => 1],
                'VIDEO_CLAIM_GOVERNANCE' => ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED', 'canonical_id' => $claimId, 'revision' => 1],
                'VIDEO_EVIDENCE_GOVERNANCE' => ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED', 'canonical_id' => $evidenceId, 'revision' => 1],
            ],
        );
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events, null, static function (array $context) use ($videoId): array {
            return ['status' => 'VERIFIED', 'items' => [['video_id' => $videoId, 'completion' => ['owner_type' => 'video', 'owner_id' => $videoId, 'status' => 'COMPLETE', 'complete' => true, 'canonical_readback' => ['canonical_id' => $videoId], 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'content_state' => 'CONTENT_COMPLETE', 'public_state' => 'READY', 'frontend_state' => 'VERIFIED', 'blockers' => []]]], 'blockers' => []];
        }, $validator));

        $result = $service->retry(['capture_id' => $capture->captureId, 'resume_mode' => 'RETRY', 'resume_children' => ['video']]);
        $children = [];
        foreach ($result['capture']['diagnostics']['completion']['children'] as $child) $children[(string) ($child['owner_type'] ?? '')] = $child;

        self::assertSame('COMPLETE', $result['capture']['status']);
        self::assertSame('COMPLETE', $children['source']['status']);
        self::assertSame('COMPLETE', $children['knowledge']['status']);
        self::assertSame('COMPLETE', $children['evidence']['status'], json_encode($result['capture']['diagnostics']['completion'], JSON_THROW_ON_ERROR));
        self::assertSame(1, $children['source']['canonical_readback']['revision'], json_encode($result['capture']['diagnostics']['completion'], JSON_THROW_ON_ERROR));
        self::assertSame(1, $children['knowledge']['canonical_readback']['revision']);
        self::assertSame(1, $children['evidence']['canonical_readback']['revision']);
        self::assertSame([], $result['capture']['diagnostics']['completion']['blockers']);
        self::assertArrayNotHasKey('semantic', $events);

        $publicRead = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $claims, $evidenceRepository,
            sources: $sources,
            captures: $captures,
        );
        self::assertNull($publicRead->sourceGet($sourceId));
        self::assertNull($publicRead->knowledgeGet($claimId));
        self::assertNull($publicRead->evidenceGet($evidenceId));
        $projection = $publicRead->captureGet($capture->captureId);
        self::assertSame('COMPLETE', $projection['capture_status']);
        self::assertSame([], $projection['blockers']);
        self::assertFalse($projection['retry']['eligible']);
        self::assertSame('CAPTURE_RETRY_NOT_ALLOWED', $projection['retry']['reason']);
        $ownerStatuses = [];
        foreach ($projection['owners'] as $owner) $ownerStatuses[(string) ($owner['owner_type'] ?? '')] = $owner['status'] ?? null;
        self::assertSame('COMPLETE', $ownerStatuses['source']);
        self::assertSame('COMPLETE', $ownerStatuses['knowledge']);
        self::assertSame('COMPLETE', $ownerStatuses['evidence']);
        self::assertSame('COMPLETE', $ownerStatuses['video']);
    }

    public function test_missing_internal_dependency_remains_blocked(): void
    {
        $claims = $this->createMock(KnowledgeRepository::class);
        $claims->method('findByCanonicalId')->willReturn(null);
        $validator = new CanonicalDependencyValidator($claims, $this->createMock(SourceRepository::class), $this->createMock(EvidenceRepository::class));
        $captures = new ContinuationCaptureRepository();
        $events = [];
        $coordinator = $this->coordinator($captures, $events, null, null, $validator);
        $method = new \ReflectionMethod($coordinator, 'refreshVideoDependencyWrites');
        $method->setAccessible(true);
        $claimId = UuidCodec::newV7();
        $result = $method->invoke($coordinator, ['writes' => [['entity_type' => 'knowledge', 'canonical_id' => $claimId, 'completion' => ['owner_type' => 'knowledge', 'owner_id' => $claimId, 'canonical_readback' => ['canonical_id' => $claimId, 'revision' => 1], 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE']]]]);

        self::assertSame('BLOCKED', $result['writes'][0]['completion']['canonical_state']);
        self::assertContains('CANONICAL_CLAIM_REQUIRED', $result['writes'][0]['completion']['blockers']);
        self::assertFalse($result['writes'][0]['completion']['complete']);
    }

    public function test_missing_dependency_validator_is_reported_as_runtime_composition_invalid(): void
    {
        $captures = new ContinuationCaptureRepository();
        $events = [];
        $coordinator = $this->coordinator($captures, $events);
        $method = new \ReflectionMethod($coordinator, 'refreshVideoDependencyWrites');
        $method->setAccessible(true);
        $sourceId = UuidCodec::newV7();
        $result = $method->invoke($coordinator, [
            'writes' => [[
                'entity_type' => 'knowledge',
                'canonical_id' => $sourceId,
                'completion' => ['owner_type' => 'knowledge', 'owner_id' => $sourceId, 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE'],
            ]],
        ], ['VIDEO_SOURCE_GOVERNANCE' => ['canonical_id' => $sourceId, 'revision' => 1]]);

        self::assertSame('BLOCKED', $result['writes'][0]['completion']['canonical_state']);
        self::assertContains('RUNTIME_COMPOSITION_INVALID', $result['writes'][0]['completion']['blockers']);
        self::assertFalse($result['writes'][0]['completion']['complete']);
    }

    public function test_internal_dependency_revision_drift_remains_blocked(): void
    {
        $claimId = UuidCodec::newV7();
        $claim = new KnowledgeClaim($claimId, 'claim:drift', 'Current claim', revision: 2);
        $claims = $this->createMock(KnowledgeRepository::class);
        $claims->method('findByCanonicalId')->with($claimId)->willReturn($claim);
        $validator = new CanonicalDependencyValidator($claims, $this->createMock(SourceRepository::class), $this->createMock(EvidenceRepository::class));
        $captures = new ContinuationCaptureRepository();
        $events = [];
        $coordinator = $this->coordinator($captures, $events, null, null, $validator);
        $method = new \ReflectionMethod($coordinator, 'refreshVideoDependencyWrites');
        $method->setAccessible(true);
        $result = $method->invoke($coordinator, ['writes' => [['entity_type' => 'knowledge', 'canonical_id' => $claimId, 'completion' => ['owner_type' => 'knowledge', 'owner_id' => $claimId, 'canonical_readback' => ['canonical_id' => $claimId, 'revision' => 1], 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE']]]]);

        self::assertSame('BLOCKED', $result['writes'][0]['completion']['canonical_state']);
        self::assertContains('CANONICAL_DEPENDENCY_REVISION_MISMATCH', $result['writes'][0]['completion']['blockers']);
        self::assertFalse($result['writes'][0]['completion']['complete']);
    }

    public function test_complete_capture_without_missing_owner_is_read_only_no_op(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->resumableVideoCapture('PARTIAL', 'COMPLETE', false);
        $capture = new CaptureRecord($capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, CaptureStage::READY_FOR_PUBLICATION->value, 'PARTIAL', $capture->articleId, $capture->articleStateToken, $capture->assets, $capture->context, $capture->diagnostics, $capture->phaseReceipts, $capture->revision, $capture->createdAt, $capture->updatedAt);
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $result = $service->retry([
            'capture_id' => $capture->captureId,
            'idempotency_key' => $capture->idempotencyKey,
            'resume_mode' => 'RETRY',
        ]);

        self::assertSame('REPLAYED', $result['retry']['status']);
        self::assertNull($result['retry']['code']);
        self::assertArrayNotHasKey('semantic', $events);
        self::assertCount(0, $addenda->records);
    }

    public function test_transport_routes_explicit_retry_to_retry_boundary_not_addendum_boundary(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $coordinator = $this->coordinator($captures, $events);
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator);
        $documentation = new McpDocumentationRegistry();
        $checkpoint = $documentation->bootstrap();
        $transport = new McpTransport(
            new McpReadHandler(
                $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
                $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class),
                $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class),
                $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
                null, $this->createMock(SourceRepository::class), null, null, null,
            ),
            new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())),
            static fn (string $capability): bool => true,
            documentation: $documentation,
            capture: $coordinator,
            captureContinuation: $service,
        );

        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'nhk.capture.ingest',
            'arguments' => [
                'capture_id' => $capture->captureId,
                'idempotency_key' => $capture->idempotencyKey,
                'resume_mode' => 'RETRY',
                'documentation_checkpoint' => ['manifest_hash' => $checkpoint['manifest_hash'], 'documentation_version' => $checkpoint['documentation_version']],
            ],
        ]]);

        self::assertSame(200, $response['status']);
        self::assertSame('RETRY', $response['body']['result']['structuredContent']['retry']['mode']);
        self::assertArrayNotHasKey('addendum', $response['body']['result']['structuredContent']);
        self::assertCount(0, $addenda->records);
    }

    public function test_existing_capture_continuation_preserves_governed_provenance_packets(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'provenance-packets-continuation',
            'intent' => 'KNOWLEDGE_DELTA',
            'text' => '',
            'metadata' => ['provenance_packets' => ['sources' => [['stable_key' => 'nhk:source:test']]]],
        ]);

        self::assertSame(['sources' => [['stable_key' => 'nhk:source:test']]], $events['provenance_packets']);
    }

    public function test_addendum_reuses_same_capture_and_article_without_replaying_physical_phase(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-original',
            hash('sha256', 'original'),
            CaptureStage::READY_FOR_PUBLICATION->value,
            'PARTIAL',
            342,
            'state-342',
            [],
            ['raw_input' => 'Ghi chú ban đầu.', 'subject_hints' => ['Odo 30'], 'content_intent' => ['intent' => 'TEXT_ARTICLE', 'article_required' => true]],
            ['composition' => ['title' => 'Bài 342']],
            [],
        );
        $captures->create($capture);
        $events = [];
        $coordinator = $this->coordinator($captures, $events);
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator);
        $input = ['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-1', 'text' => 'Bổ sung tri thức thực địa.', 'subject_hints' => ['Odo 30']];

        $first = $service->execute($input);
        $replay = $service->execute($input);

        self::assertSame('COMPLETED', $first['addendum']['status']);
        self::assertSame($capture->captureId, $first['capture']['capture_id']);
        self::assertSame(342, $first['capture']['article_id']);
        self::assertSame($first['addendum']['addendum_id'], $replay['addendum']['addendum_id']);
        self::assertSame(1, $events['semantic']);
        self::assertSame(1, $events['media']);
        self::assertArrayNotHasKey('physical', $events);
        self::assertArrayNotHasKey('draft', $events);
        self::assertStringContainsString('Ghi chú ban đầu.', $events['merged_text']);
        self::assertStringContainsString('Bổ sung tri thức thực địa.', $events['merged_text']);
    }

    public function test_same_addendum_key_with_changed_payload_conflicts_without_rerunning_capture(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));
        $base = ['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-conflict', 'text' => 'Bổ sung A.'];

        $service->execute($base);
        $conflict = $service->execute(array_merge($base, ['text' => 'Bổ sung B.']));

        self::assertSame('IDEMPOTENCY_CONFLICT', $conflict['addendum']['status']);
        self::assertSame('CAPTURE_ADDENDUM_IDEMPOTENCY_CONFLICT', $conflict['addendum']['diagnostics']['code']);
        self::assertSame(1, $events['semantic']);
    }

    public function test_second_addendum_resumes_from_the_previous_addendum_checkpoint(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-a', 'text' => 'Bổ sung A.']);
        $second = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-b', 'text' => 'Bổ sung B.']);

        self::assertSame('COMPLETED', $second['addendum']['status']);
        self::assertSame(2, $events['semantic']);
        self::assertStringContainsString('Bổ sung A.', $events['merged_text']);
        self::assertStringContainsString('Bổ sung B.', $events['merged_text']);
        self::assertCount(2, $second['capture']['context']['continuations']);
        self::assertStringContainsString('Bổ sung A.', $second['capture']['context']['continuation_state']['raw_input']);
    }

    public function test_second_addendum_persists_both_audit_entries_in_order(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $first = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-a', 'text' => 'Bổ sung A.']);
        $second = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-b', 'text' => 'Bổ sung B.']);
        $audit = $second['capture']['context']['continuations'];

        self::assertSame($capture->captureId, $second['capture']['capture_id']);
        self::assertSame(342, $second['capture']['article_id']);
        self::assertSame($capture->requestFingerprint, $second['capture']['request_fingerprint']);
        self::assertCount(2, $audit);
        self::assertNotSame($audit[0]['addendum_id'], $audit[1]['addendum_id']);
        self::assertSame(['addendum-a', 'addendum-b'], array_column($audit, 'idempotency_key'));
        self::assertTrue($audit[0]['capture_revision'] < $audit[1]['capture_revision']);
        self::assertSame($second['capture']['revision'], $audit[1]['capture_revision']);
        self::assertSame($second['addendum']['capture_revision'], $audit[1]['capture_revision']);
        self::assertTrue($first['capture']['revision'] < $second['capture']['revision']);
    }

    public function test_editorial_replacement_continuation_replaces_prior_body_without_appending_it(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $replacement = $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'article-replacement',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bản bài viết công khai mới.',
            'metadata' => ['editorial_replacement' => true],
        ]);

        self::assertSame('COMPLETED', $replacement['addendum']['status']);
        self::assertSame('Bản bài viết công khai mới.', $events['merged_text']);
        self::assertSame('Bản bài viết công khai mới.', $replacement['capture']['context']['continuation_state']['raw_input']);
        self::assertSame(true, $replacement['addendum']['payload']['metadata']['editorial_replacement']);
    }

    public function test_replaying_completed_addendum_does_not_append_duplicate_audit_or_revision(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));
        $input = ['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-replay', 'text' => 'Bổ sung chỉ một lần.'];

        $first = $service->execute($input);
        $afterFirst = $captures->findById($capture->captureId);
        $replay = $service->execute($input);
        $afterReplay = $captures->findById($capture->captureId);

        self::assertSame($first['addendum']['addendum_id'], $replay['addendum']['addendum_id']);
        self::assertSame(1, count($afterReplay?->context['continuations'] ?? []));
        self::assertSame($afterFirst?->revision, $afterReplay?->revision);
        self::assertSame(1, $events['semantic']);
        self::assertSame(1, $events['media']);
    }

    public function test_same_addendum_can_resume_governance_without_creating_a_duplicate_addendum(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));
        $input = ['capture_id' => $capture->captureId, 'idempotency_key' => 'governance-same-key', 'text' => 'Bổ sung cần duyệt.'];

        $first = $service->execute($input);
        $resumed = $service->execute($input + ['governance' => ['approval_confirmed' => true]]);

        self::assertSame($first['addendum']['addendum_id'], $resumed['addendum']['addendum_id']);
        self::assertCount(1, $addenda->records);
        self::assertSame(2, $events['semantic']);
        self::assertStringContainsString('Ghi chú ban đầu.', $events['merged_text']);
    }

    public function test_governance_only_replay_restores_persisted_provenance_packet(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));
        $input = [
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'governance-provenance-only-replay',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bài viết đã biên tập.',
            'metadata' => ['editorial_replacement' => true, 'provenance_packets' => ['sources' => [['stable_key' => 'nhk:source:replay']]]],
        ];

        $first = $service->execute($input);
        $resumed = $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'governance-provenance-only-replay',
            'governance' => ['approval_confirmed' => true],
        ]);

        self::assertSame($first['addendum']['addendum_id'], $resumed['addendum']['addendum_id']);
        self::assertSame('COMPLETED', $resumed['addendum']['status']);
        self::assertSame(['sources' => [['stable_key' => 'nhk:source:replay']]], $events['provenance_packets']);
        self::assertCount(1, $addenda->records);
    }

    public function test_rejected_addendum_retains_sanitized_audit_payload_without_files(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));
        $input = [
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'addendum-rejected',
            'text' => 'Nội dung cần audit.',
            'subject_hints' => ['Odo 30'],
            'observations' => [['kind' => 'field-note', 'value' => '6 côn 8 búa']],
            'metadata' => ['source' => 'user'],
            'files' => [['name' => 'private.jpg', 'tmp_name' => '/private/tmp/private.jpg', 'size' => 123]],
        ];

        $result = $service->execute($input);

        self::assertSame('FAILED', $result['addendum']['status']);
        self::assertSame('CAPTURE_ADDENDUM_FILES_NOT_ALLOWED', $result['addendum']['diagnostics']['code']);
        self::assertSame([
            'text' => 'Nội dung cần audit.',
            'subject_hints' => ['Odo 30'],
            'observations' => [['kind' => 'field-note', 'value' => '6 côn 8 búa']],
            'metadata' => ['source' => 'user'],
        ], $result['addendum']['payload']);
        self::assertArrayNotHasKey('files', $result['addendum']['payload']);
        self::assertArrayNotHasKey('tmp_name', $result['addendum']['payload']);
    }

    public function test_governance_apply_readback_is_consumed_by_capture_continuation_without_publication(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $applied = false;
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static function (): array { throw new \RuntimeException('physical phase must not replay'); },
            static function (): array { throw new \RuntimeException('draft phase must not replay'); },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$applied, &$events): array {
                $events['governance_lifecycle'] = ['PROPOSAL', 'SUBMIT', 'APPROVE', 'ELIGIBILITY', 'CONTROLLED_APPLY', 'CANONICAL_READ_BACK'];
                return $applied
                    ? ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => 'canonical-claim-36-8', 'revision' => 2, 'subject_id' => 'variant-36-8'], 'writes' => []]
                    : ['status' => 'REVIEW_REQUIRED', 'writes' => [['kind' => 'claim_candidate', 'scope' => 'capture', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]];
            },
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['SEMANTIC_REVIEW_REQUIRED', 'MEDIAUSAGE_INCOMPLETE']],
            static fn (array $context): array => ['status' => 'verified'],
        );
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator);

        $first = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'governance-pending', 'text' => 'Côn chữ U màu trắng.', 'subject_hints' => ['variant-36-8', 'Côn chữ U']]);
        // The normal operator lifecycle is Proposal → Submit → Approve →
        // Eligibility → Controlled Apply → canonical read-back. This test
        // supplies that verified result before resuming the same Capture.
        $applied = true;
        $second = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'governance-applied', 'text' => '']);

        self::assertSame('REVIEW_REQUIRED', $first['capture']['diagnostics']['semantic_write_back']['status']);
        self::assertSame('APPLIED', $second['capture']['diagnostics']['semantic_write_back']['status']);
        self::assertSame($capture->articleId, $second['capture']['article_id']);
        self::assertSame(['PROPOSAL', 'SUBMIT', 'APPROVE', 'ELIGIBILITY', 'CONTROLLED_APPLY', 'CANONICAL_READ_BACK'], $events['governance_lifecycle']);
        self::assertContains('SEMANTIC_REVIEW_REQUIRED', $second['capture']['diagnostics']['publication']['blockers']);
        self::assertSame('verified', $second['capture']['diagnostics']['final_read_back']['status']);
    }

    public function test_addendum_rejects_files_and_keeps_original_capture_request_immutable(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $result = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-files', 'text' => 'Không upload lại.', 'files' => ['unexpected']]);

        self::assertSame('FAILED', $result['addendum']['status']);
        self::assertSame('CAPTURE_ADDENDUM_FILES_NOT_ALLOWED', $result['addendum']['diagnostics']['code']);
        self::assertSame($capture->requestFingerprint, $captures->findById($capture->captureId)?->requestFingerprint);
    }

    public function test_deterministic_proposal_binding_failure_is_system_blocked_not_retryable(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events, static function (): array {
            throw new ProposalSubjectBindingInvalid('wording changed but binding remains invalid');
        }));

        $result = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'binding-failure', 'text' => 'Bổ sung có lỗi binding.']);

        self::assertSame('SYSTEM_BLOCKED', $result['capture']['status']);
        self::assertSame('PROPOSAL_SUBJECT_BINDING_INVALID', $result['capture']['diagnostics']['failure']['code']);
        self::assertSame('SYSTEM_BLOCKED', $result['capture']['diagnostics']['failure']['classification']);
        self::assertSame('BLOCKED', $result['capture']['phase_receipts']['SEMANTICS_RECONCILED']['status']);
    }

    public function test_text_only_legacy_continuation_does_not_reenter_original_video_enrichment(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-legacy-video',
            hash('sha256', 'legacy-video'),
            CaptureStage::SUBJECTS_RESOLVED->value,
            'FAILED_RETRYABLE',
            445,
            'state-445',
            [],
            [
                'raw_input' => 'Odo 36/8 Westminster.',
                'subject_hints' => ['Odo 36/8'],
                // A legacy immutable request may document the original
                // Video, but it is not a continuation delta or a planning
                // asset and must not trigger a new external fetch.
                'original_request' => ['video' => ['url' => 'https://youtu.be/_VWcu0gqg5s']],
            ],
            ['failure' => ['code' => 'VIDEO_RELATION_REQUIRES_EVIDENCE']],
            [],
        );
        $captures->create($capture);
        $videoCalls = 0;
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (): array => ['items' => []],
            static fn (): array => ['post_id' => 445, 'state_token' => 'state-445'],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            static function () use (&$videoCalls): array {
                $videoCalls++;
                throw new \RuntimeException('VIDEO_ENRICHMENT_MUST_NOT_RUN');
            },
        );
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator);

        $result = $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'legacy-text-only-addendum',
            'text' => 'Bổ sung ghi chú hiện trường.',
        ]);

        self::assertSame($capture->captureId, $result['capture']['capture_id']);
        self::assertSame(445, $result['capture']['article_id']);
        self::assertSame('COMPLETED', $result['addendum']['status']);
        self::assertSame(0, $videoCalls);
        self::assertArrayNotHasKey('VIDEO_ENRICHED', $result['capture']['phase_receipts']);
    }

    public function test_video_only_resume_keeps_original_subject_when_empty_addendum_reparse_resolves_nothing(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $variant = '852da54d-457a-4397-a16d-52d9452ba766';
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-video-resume-subject-lock',
            hash('sha256', 'capture-video-resume-subject-lock'),
            CaptureStage::READY_FOR_PUBLICATION->value,
            'PARTIAL',
            450,
            'state-450',
            [[
                'kind' => 'video',
                'video_proposal' => [
                    'entity_type' => 'video',
                    'operation' => 'ingest',
                    'subject_id' => UuidCodec::newV7(),
                    'payload' => ['metadata' => [
                        'source' => ['platform' => 'youtube', 'external_video_id' => '4NmkQFrNeWQ', 'source_title' => 'Odo 36/8 source snapshot'],
                        'subject_resolution_packet' => ['id' => $variant, 'type' => 'variant', 'name' => 'Odo 36/8'],
                    ]],
                ],
            ]],
            [
                'raw_input' => 'Odo 36/8 trong video.',
                'subject_hints' => ['Odo 36/8'],
                'title' => 'Đồng hồ Odo 36/8 mặt số nổi, thùng kính chuông hiếm gặp',
            ],
            ['subjects' => ['status' => 'resolved', 'primary' => ['id' => $variant, 'type' => 'variant', 'name' => 'Odo 36/8'], 'resolved' => [['id' => $variant, 'type' => 'variant', 'name' => 'Odo 36/8']]], 'composition' => ['title' => 'Đồng hồ Odo 36/8 mặt số nổi, thùng kính chuông hiếm gặp']],
            [],
        );
        $captures->create($capture);
        $seenResolution = null;
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 450, 'state_token' => 'state-450'],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$seenResolution): array {
                $seenResolution = $context['subject_resolution'];
                return ['status' => 'REVIEW_REQUIRED', 'writes' => []];
            },
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
        );
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator);

        $result = $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'resume-video-subject-lock',
            'text' => '',
            'resume_children' => ['video'],
        ]);

        self::assertSame('COMPLETED', $result['addendum']['status']);
        self::assertSame($capture->captureId, $result['capture']['capture_id']);
        self::assertIsArray($seenResolution);
        self::assertSame($variant, $seenResolution['primary']['id']);
        self::assertSame('variant', $seenResolution['primary']['type']);
    }

    public function test_ambiguous_video_retry_accepts_only_confirmed_candidate_on_same_capture_and_preserves_video_payload(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $captureId = '01a0cb8d-31a5-700b-8780-3793df5d0969';
        $videoId = '01a06815-1e51-7964-b004-1ba79e488ad1';
        $selected = '5f6c98ca-869a-4418-a8a4-1a32eb931c5e';
        $other = '5f6c98ca-869a-4418-a8a4-1a32eb931c5f';
        $candidates = [
            'Odo 36/10' => [
                ['id' => $selected, 'type' => 'variant', 'stable_key' => 'nhk:variant:odo.36.10.two-tune', 'name' => 'Odo 36/10 two-tune', 'revision' => 4],
                ['id' => $other, 'type' => 'variant', 'stable_key' => 'nhk:variant:odo.36.10.other', 'name' => 'Odo 36/10 other', 'revision' => 2],
            ],
        ];
        $assets = [[
            'kind' => 'video',
            'video_id' => $videoId,
            'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => '', 'payload' => ['canonical_id' => $videoId, 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'P4KaHX3LBOw', 'source_url' => 'https://youtu.be/P4KaHX3LBOw', 'source_title' => 'Golden Odo 36'], 'external_video_id' => 'P4KaHX3LBOw']]],
        ]];
        $packet = ['packet_version' => 1, 'status' => 'ambiguous', 'canonical_subject_id' => '', 'entity_type' => '', 'stable_key' => '', 'canonical_name' => '', 'revision' => 0, 'diagnostics' => ['candidates' => $candidates, 'diagnostics' => ['AMBIGUOUS_SUBJECT_REVIEW']]];
        $capture = new CaptureRecord(
            $captureId,
            'golden-ambiguous-video',
            hash('sha256', 'golden-ambiguous-video'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'FAILED_RETRYABLE',
            null,
            null,
            $assets,
            ['purpose' => 'EDITORIAL', 'raw_input' => 'Odo 36/10', 'title' => 'Golden Odo 36', 'content_intent' => ['intent' => 'VIDEO', 'article_required' => false], 'subject_resolution_packet' => $packet],
            ['failure' => ['code' => 'AMBIGUOUS_SUBJECT_REVIEW', 'classification' => 'FAILED_RETRYABLE'], 'subjects' => ['status' => 'ambiguous', 'primary' => null, 'resolved' => [], 'candidates' => $candidates, 'diagnostics' => ['AMBIGUOUS_SUBJECT_REVIEW']]],
            [],
        );
        $captures->create($capture);
        $events = [];
        $videoPipelineCalls = 0;
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events, static function (array $context) use (&$events): array {
            $events['subject_resolution'] = $context['subject_resolution'] ?? null;
            $events['video_payload'] = $context['assets'][0]['video_proposal']['payload'] ?? null;
            $events['semantic'] = ($events['semantic'] ?? 0) + 1;
            return ['status' => 'REVIEW_REQUIRED', 'writes' => []];
        }, static function (array $context) use (&$videoPipelineCalls): array {
            $videoPipelineCalls++;
            return ['status' => 'READY', 'quality' => 'READY', 'blockers' => []];
        }));

        $result = $service->retry([
            'capture_id' => $captureId,
            'idempotency_key' => $capture->idempotencyKey,
            'resume_mode' => 'RETRY',
            'resume_children' => ['video'],
            'subject_reconciliation' => ['confirmed' => true, 'candidate_uuid' => $selected],
        ]);

        self::assertSame($captureId, $result['capture']['capture_id']);
        self::assertSame($videoId, $result['capture']['assets'][0]['video_id']);
        self::assertSame('P4KaHX3LBOw', $result['capture']['assets'][0]['video_proposal']['payload']['metadata']['source']['external_video_id']);
        self::assertSame('https://youtu.be/P4KaHX3LBOw', $result['capture']['assets'][0]['video_proposal']['payload']['metadata']['source']['source_url']);
        self::assertSame('Golden Odo 36', $result['capture']['assets'][0]['video_proposal']['payload']['metadata']['source']['source_title']);
        self::assertSame($selected, $result['capture']['context']['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame('resolved', $result['capture']['context']['subject_resolution_packet']['status']);
        self::assertSame($selected, $events['subject_resolution']['primary']['id']);
        self::assertSame($assets[0]['video_proposal']['payload']['metadata']['source'], $events['video_payload']['metadata']['source']);
        self::assertSame(1, $events['semantic']);
        self::assertSame(1, $videoPipelineCalls);
        self::assertSame('READY', $result['capture']['diagnostics']['video_publication']['quality']);
        self::assertCount(0, $addenda->records);
    }

    public function test_ambiguous_video_retry_fails_closed_for_uuid_outside_candidate_set_without_persisting(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $candidate = UuidCodec::newV7();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'ambiguous-candidate-closed',
            hash('sha256', 'ambiguous-candidate-closed'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'FAILED_RETRYABLE',
            null,
            null,
            [],
            ['content_intent' => ['intent' => 'VIDEO', 'article_required' => false], 'subject_resolution_packet' => ['status' => 'ambiguous', 'diagnostics' => ['candidates' => [['id' => $candidate, 'type' => 'variant', 'revision' => 1]]]]],
            ['failure' => ['code' => 'AMBIGUOUS_SUBJECT_REVIEW']],
            [],
        );
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $result = $service->retry(['capture_id' => $capture->captureId, 'idempotency_key' => $capture->idempotencyKey, 'resume_mode' => 'RETRY', 'subject_reconciliation' => ['confirmed' => true, 'candidate_uuid' => UuidCodec::newV7()]]);

        self::assertSame('CAPTURE_SUBJECT_RECONCILIATION_CANDIDATE_NOT_ALLOWED', $result['retry']['code']);
        self::assertSame($capture->revision, $captures->findById($capture->captureId)?->revision);
        self::assertArrayNotHasKey('semantic', $events);
    }

    public function test_video_enrichment_started_receipt_survives_callback_failure(): void
    {
        $captures = new ContinuationCaptureRepository();
        $videoCalls = 0;
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (): array => ['items' => []],
            static fn (): array => ['post_id' => 445, 'state_token' => 'state-445'],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            static function () use (&$videoCalls): array {
                $videoCalls++;
                throw new \RuntimeException('BOUNDED_VIDEO_ENRICHMENT_FAILURE');
            },
        );

        $result = $coordinator->execute([
            'purpose' => 'EDITORIAL',
            'idempotency_key' => 'new-video-capture',
            'text' => 'Odo 36/8 Westminster.',
            'video' => ['url' => 'https://youtu.be/_VWcu0gqg5s'],
        ]);

        self::assertSame(1, $videoCalls);
        self::assertSame('FAILED_RETRYABLE', $result->status);
        self::assertSame('FAILED', $result->phaseReceipts['VIDEO_ENRICHED']['status']);
        self::assertSame('FAILED_RETRYABLE', $result->phaseReceipts['VIDEO_ENRICHED']['result']);
        self::assertArrayNotHasKey('VIDEO_SOURCE_GOVERNANCE', $result->phaseReceipts);
        self::assertArrayNotHasKey('VIDEO_CLAIM_GOVERNANCE', $result->phaseReceipts);
        self::assertArrayNotHasKey('VIDEO_EVIDENCE_GOVERNANCE', $result->phaseReceipts);
    }

    public function test_partial_semantic_blocker_finalizes_semantics_receipt(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService(
            $captures,
            $addenda,
            $this->coordinator($captures, $events, static fn (array $context): array => [
                'status' => 'PARTIAL',
                'blockers' => ['VIDEO_RELATION_REQUIRES_EVIDENCE'],
                'writes' => [],
            ]),
        );

        $result = $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'partial-semantic-blocker',
            'text' => 'Bổ sung ghi chú nhưng quan hệ Video còn thiếu Evidence.',
        ]);

        $receipt = $result['capture']['phase_receipts']['SEMANTICS_RECONCILED'];
        self::assertSame('BLOCKED', $receipt['status']);
        self::assertSame('PARTIAL', $receipt['result']);
        self::assertSame('VIDEO_RELATION_REQUIRES_EVIDENCE', $receipt['failure_code']);
        self::assertNotNull($receipt['completed_at']);
    }

    public function test_evidence_blocker_classification_is_independent_of_exception_wording(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService(
            $captures,
            $addenda,
            $this->coordinator($captures, $events, static function (): array {
                throw new VideoRelationEvidenceRequired('wording intentionally changed');
            }),
        );

        $result = $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'typed-evidence-blocker',
            'text' => 'Bổ sung nhưng Evidence chưa sẵn sàng.',
        ]);

        self::assertSame('REVIEW_REQUIRED', $result['capture']['status']);
        self::assertSame(VideoRelationEvidenceRequired::ERROR_CODE, $result['capture']['diagnostics']['failure']['code']);
        self::assertSame('REVIEW_REQUIRED', $result['capture']['diagnostics']['failure']['classification']);
        self::assertSame('REVIEW_REQUIRED', $result['capture']['phase_receipts']['SEMANTICS_RECONCILED']['status']);
    }

    private function capture(): CaptureRecord
    {
        return new CaptureRecord(UuidCodec::newV7(), 'capture-original-' . bin2hex(random_bytes(2)), hash('sha256', 'original'), CaptureStage::READY_FOR_PUBLICATION->value, 'PARTIAL', 342, 'state-342', [], ['raw_input' => 'Ghi chú ban đầu.', 'subject_hints' => ['Odo 30']], ['composition' => ['title' => 'Bài 342']], []);
    }

    private function resumableVideoCapture(string $captureStatus, string $completionStatus, bool $missing = true): CaptureRecord
    {
        $videoId = UuidCodec::newV7();
        $completion = [
            'status' => $completionStatus,
            'complete' => !$missing,
            'required_owners' => [['owner_type' => 'video', 'owner_id' => $videoId]],
            'missing_required_owners' => $missing ? [['owner_type' => 'video', 'owner_id' => $videoId]] : [],
            'children' => $missing ? [['owner_type' => 'video', 'owner_id' => $videoId, 'status' => 'PARTIAL', 'complete' => false]] : [['owner_type' => 'video', 'owner_id' => $videoId, 'status' => 'COMPLETE', 'complete' => true]],
            'resume_hints' => ['resume_children' => $missing ? ['video'] : []],
        ];
        return new CaptureRecord(
            UuidCodec::newV7(),
            'capture-resumable-video-' . bin2hex(random_bytes(2)),
            hash('sha256', 'resumable-video'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            $captureStatus,
            null,
            null,
            [],
            ['raw_input' => 'W64 editorial input.', 'content_intent' => ['intent' => 'VIDEO', 'article_required' => false]],
            ['completion' => $completion, 'resume_hints' => $completion['resume_hints']],
            [],
        );
    }

    /** @param array<string,int|string> $events */
    private function coordinator(ContinuationCaptureRepository $captures, array &$events, ?callable $semantic = null, ?callable $videoVerifier = null, ?CanonicalDependencyValidator $canonicalDependencies = null): EditorialCaptureCoordinator
    {
        return new EditorialCaptureCoordinator(
            $captures,
            static function () use (&$events): array { $events['physical'] = ($events['physical'] ?? 0) + 1; throw new \RuntimeException('physical phase must not replay'); },
            static function () use (&$events): array { $events['draft'] = ($events['draft'] ?? 0) + 1; throw new \RuntimeException('draft phase must not replay'); },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            $semantic ?? static function (array $context) use (&$events): array { $events['semantic'] = ($events['semantic'] ?? 0) + 1; $events['merged_text'] = $context['raw_input']; $events['provenance_packets'] = $context['provenance_packets'] ?? null; $events['existing_capture_continuation'] = ($context['existing_capture_continuation'] ?? false) === true; $events['continuation_idempotency_key'] = $context['continuation_idempotency_key'] ?? null; return ['status' => 'REVIEW_REQUIRED', 'writes' => []]; },
            new ArticleComposer(),
            static function (array $context) use (&$events): array { $events['media'] = ($events['media'] ?? 0) + 1; return ['status' => 'RECONCILED']; },
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            static function (array $context): array { return ['ok' => true, 'state_token' => $context['expected_state_token'] ?? 'state-342']; },
            null,
            null,
            null,
            null,
            $videoVerifier,
            canonicalDependencies: $canonicalDependencies,
        );
    }
}

final class ContinuationCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    public array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { foreach ($this->records as $record) if ($record->idempotencyKey === $key) return $record; return null; }
    public function findById(string $captureId): ?CaptureRecord { return $this->records[$captureId] ?? null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
}

final class ContinuationAddendumRepository implements CaptureAddendumRepository
{
    /** @var array<string,CaptureAddendumRecord> */
    public array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureAddendumRecord { return $this->records[$key] ?? null; }
    public function create(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
    public function save(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
}
