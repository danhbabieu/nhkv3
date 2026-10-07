<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpReadHandler;
use NHK\Core\Application\Capture\CapturePhaseReceiptReducer;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Infrastructure\Capture\WpdbCaptureRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Contracts\Video\VideoRepository;
use PHPUnit\Framework\TestCase;

final class McpCaptureReadContractTest extends TestCase
{
    public function test_live_persisted_shape_is_reconciled_by_real_wpdb_hydration_before_capture_get(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        $id = '01a1105c-4cc6-7529-ad3e-6bc652b4ae7f';
        $row = [
            'capture_uuid' => UuidCodec::toBinary($id),
            'idempotency_key' => 'live-capture-retry',
            'request_fingerprint' => hash('sha256', 'live-capture-retry'),
            'stage' => 'SEMANTICS_RECONCILED', 'status' => 'REVIEW_REQUIRED', 'wp_post_id' => 0, 'wp_state_token' => '',
            'assets_json' => '[]',
            'context_json' => json_encode(['content_intent' => ['intent' => 'TEXT_ARTICLE', 'article_required' => true], 'subject_resolution_packet' => ['status' => 'resolved', 'canonical_subject_id' => '984658bf-19a6-4daa-a220-2a6c13af81ed', 'entity_type' => 'model', 'revision' => 1]], JSON_THROW_ON_ERROR),
            'diagnostics_json' => json_encode(['failure' => ['code' => 'CANONICAL_READBACK_UNVERIFIED', 'classification' => 'FAILED_RETRYABLE', 'message' => 'UTF8_INVALID_INPUT'], 'completion' => ['status' => 'BLOCKED', 'canonical_readback_verified' => false, 'blockers' => ['CANONICAL_READBACK_UNVERIFIED']], 'article_resolution' => ['research' => ['ready_for_draft' => true, 'blockers' => [], 'warnings' => ['MEDIA_PLACEHOLDER_OR_UNAVAILABLE']]]], JSON_THROW_ON_ERROR),
            'phase_receipts_json' => json_encode(['FAILED_RETRYABLE' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1, 'attempts' => [['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1]], 'latest' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1]], 'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]], 'CONTENT_PREPARATION' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]], 'SEMANTICS_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]], 'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CANONICAL_READBACK_UNVERIFIED', 'attempt_no' => 2, 'attempts' => [['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1], ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CANONICAL_READBACK_UNVERIFIED', 'attempt_no' => 2, 'superseded_failure_codes' => ['CAPTURE_UTF8_INVALID']]], 'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CANONICAL_READBACK_UNVERIFIED', 'attempt_no' => 2]]], JSON_THROW_ON_ERROR),
            'revision' => 23, 'created_at' => '', 'updated_at' => '',
        ];
        $wpdb = new class($row) {
            public string $prefix = 'wp_';
            public function __construct(private array $row) {}
            public function prepare(string $query, mixed ...$args): string { return $query; }
            public function get_row(string $query, mixed $output = null): array { return $this->row; }
        };
        $repository = new WpdbCaptureRepository($wpdb);
        $hydrated = $repository->findById($id);

        self::assertNotNull($hydrated);
        self::assertSame([], $hydrated->diagnostics['completion']['blockers']);
        self::assertArrayNotHasKey('failure', $hydrated->diagnostics);
        self::assertContains('CANONICAL_READBACK_UNVERIFIED', array_column($hydrated->diagnostics['failure_history'], 'code'));

        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $repository,
        );
        $projection = $read->captureGet($id);

        self::assertSame([], $projection['blockers']);
        self::assertTrue($projection['retry']['eligible']);
        self::assertSame('STALE_REVIEW_REEVALUATABLE', $projection['retry']['reason']);
    }

    public function test_capture_read_hides_superseded_failure_from_current_blockers(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $record = new CaptureRecord(
            $id, 'capture-mcp-superseded', hash('sha256', 'capture-mcp-superseded'),
            'SEMANTICS_RECONCILED', 'PARTIAL', null, null, [], [],
            ['failure' => ['code' => 'ERROR_X'], 'completion' => ['status' => 'PARTIAL', 'blockers' => ['ERROR_X']]],
            $receipts,
        );
        $captures = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $captures,
        );

        $projection = $read->captureGet($id);

        self::assertNotContains('ERROR_X', $projection['blockers']);
        self::assertSame('ERROR_X', $record->phaseReceipts['PHASE_X']['attempts'][0]['failure_code']);
        self::assertSame('RECOVERED', $record->phaseReceipts['PHASE_X']['latest']['result']);
    }

    public function test_capture_get_uses_effective_legacy_state_for_retry_eligibility(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $record = new CaptureRecord(
            $id, 'legacy-capture-get', hash('sha256', 'legacy-capture-get'), 'SEMANTICS_RECONCILED', 'REVIEW_REQUIRED', null, null, [],
            ['content_intent' => ['intent' => 'TEXT_ARTICLE']],
            ['failure' => ['code' => 'CAPTURE_UTF8_INVALID'], 'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['CAPTURE_UTF8_INVALID']]],
            [
                'FAILED_RETRYABLE' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'current_outcome' => 'CURRENT'],
                'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
                'CONTENT_PREPARATION' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
                'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'current_outcome' => 'CURRENT'],
            ],
        );
        $captures = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $captures,
        );

        $projection = $read->captureGet($id);

        self::assertSame([], $projection['blockers']);
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE', 'capture_id' => $id], $projection['retry']);
    }

    public function test_capture_readback_reconciles_current_post_and_media_usage_over_stale_receipt(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $mediaId = UuidCodec::newV7();
        $assetId = UuidCodec::newV7();
        $media = new Media($mediaId, 'capture-readback-media', 'Current image', 'ready');
        $asset = new MediaAsset($assetId, $mediaId, 'original', 'uploads/current.jpg', hash('sha256', 'current'), 'image/jpeg', 12, 1200, 800, 'PUBLIC');
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:690', 'featured_primary');
        $record = new CaptureRecord(
            $id,
            'capture-current-canonical-state',
            hash('sha256', 'capture-current-canonical-state'),
            'FINAL_READBACK',
            'PARTIAL',
            690,
            null,
            [['kind' => 'image', 'media_id' => $mediaId, 'attachment_id' => 686, 'attachment_readback_status' => 'verified']],
            ['content_intent' => ['intent' => 'IMAGE_ARTICLE']],
            ['media_usage' => ['media_ids' => [$mediaId], 'media_dispositions' => [['media_id' => $mediaId, 'status' => 'APPLIED']], 'media_usage' => [['media_id' => $mediaId, 'active' => true]]], 'completion' => [
                'required_owners' => [['owner_type' => 'wp_post', 'owner_id' => '']],
                'children' => [
                    ['completion' => [
                        'owner_type' => 'wp_post', 'owner_id' => '', 'status' => 'PARTIAL', 'complete' => false,
                        'canonical_state' => 'BLOCKED', 'canonical_readback' => null, 'dependency_state' => 'COMPLETE',
                        'relation_or_usage_state' => 'PARTIAL', 'public_state' => 'NOT_APPLICABLE', 'frontend_state' => 'NOT_APPLICABLE',
                        'blockers' => ['MEDIAUSAGE_INCOMPLETE', 'ARTICLE_MEDIA_FEATURED_MISSING', 'REQUIRED_OWNER_READBACK_UNVERIFIED'],
                    ]],
                    ['completion' => [
                        'owner_type' => 'media', 'owner_id' => $mediaId, 'status' => 'PARTIAL', 'complete' => false,
                        'canonical_state' => 'COMPLETE', 'canonical_readback' => ['canonical_id' => $mediaId],
                        'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'PARTIAL', 'public_state' => 'READY',
                        'frontend_state' => 'VERIFIED', 'blockers' => ['MEDIAUSAGE_INCOMPLETE'],
                    ]],
                ],
            ]],
            [],
            2,
        );
        $captures = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $mediaRepository = new class($media) implements MediaRepository {
            public function __construct(private Media $media) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return [$this->media]; }
        };
        $assetRepository = new class($asset) implements MediaAssetRepository {
            public function __construct(private MediaAsset $asset) {}
            public function findByAssetId(string $id): ?MediaAsset { return $id === $this->asset->assetId ? $this->asset : null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return $mediaId === $this->asset->mediaId ? [$this->asset] : []; }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usageRepository = new class($usage) implements MediaUsageRepository {
            public function __construct(private MediaUsage $usage) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return $mediaId === $this->usage->mediaId && ($role === null || $role === $this->usage->role) ? [$this->usage] : []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return $endpointType === $this->usage->endpointType && $endpointKey === $this->usage->endpointKey && ($role === null || $role === $this->usage->role) ? [$this->usage] : []; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $mediaRepository, $assetRepository, $usageRepository,
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), captures: $captures,
        );

        $projection = $read->captureGet($id);

        self::assertSame([['owner_type' => 'wp_post', 'owner_id' => '690'], ['owner_type' => 'media', 'owner_id' => $mediaId]], $projection['required_owners']);
        self::assertSame([], $projection['missing_required_owners']);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $projection['blockers']);
        self::assertNotContains('ARTICLE_MEDIA_FEATURED_MISSING', $projection['blockers']);
        self::assertNotContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $projection['blockers']);
    }

    public function test_capture_readback_is_bounded_and_does_not_expose_request_secrets(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $record = new CaptureRecord(
            $id,
            'private-idempotency-key',
            hash('sha256', 'private-request'),
            'FINAL_READBACK',
            'PARTIAL',
            631,
            null,
            [['kind' => 'image', 'media_id' => 'media-631', 'attachment_id' => 630, 'attachment_readback_status' => 'verified']],
            ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'IMAGE_ARTICLE'], 'subject_resolution_packet' => ['status' => 'resolved', 'canonical_subject_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'entity_type' => 'model', 'canonical_name' => 'Odo 36/10'], 'raw_input' => 'private text', 'media_bindings' => [['target' => ['type' => 'wp_post', 'id' => '1:631'], 'role' => 'featured_primary', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED']]],
            ['completion' => ['status' => 'PARTIAL', 'blockers' => ['IMAGE_ARTICLE_MEDIA_REQUIRED'], 'children' => [['owner_type' => 'video', 'owner_id' => 'video-631', 'status' => 'PARTIAL'], ['owner_type' => 'video', 'owner_id' => 'video-631', 'status' => 'COMPLETE'], ['owner_type' => 'wp_post', 'owner_id' => '631', 'status' => 'INCOMPLETE']], 'required_owners' => [['owner_type' => 'wp_post', 'owner_id' => '631']], 'missing_required_owners' => [['owner_type' => 'media', 'owner_id' => 'media-631']]]],
            [],
            4,
        );
        $repository = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $repository,
        );

        $projection = $read->captureGet($id);
        self::assertSame($id, $projection['capture_id']);
        self::assertSame('found', $projection['status']);
        self::assertSame('PARTIAL', $projection['capture_status']);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $projection['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame(631, $projection['article']['post_id']);
        self::assertSame('media-631', $projection['media'][0]['media_id']);
        self::assertContains('IMAGE_ARTICLE_MEDIA_REQUIRED', $projection['blockers']);
        self::assertSame([
            ['owner_type' => 'video', 'owner_id' => 'video-631', 'status' => 'BLOCKED'],
            ['owner_type' => 'wp_post', 'owner_id' => '631', 'status' => 'PARTIAL'],
        ], $projection['owners']);
        self::assertArrayNotHasKey('idempotency_key', $projection);
        self::assertArrayNotHasKey('request_fingerprint', $projection);
        self::assertArrayNotHasKey('raw_input', $projection);
        self::assertArrayHasKey('result_packet', $projection);
        self::assertSame('PARTIAL', $projection['result_packet']['status']);
        self::assertStringNotContainsString('media-', json_encode($projection['result_packet'], JSON_THROW_ON_ERROR));
    }

    public function test_unknown_capture_returns_explicit_not_found_instead_of_null(): void
    {
        $repository = new class implements CaptureRepository {
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $repository,
        );

        self::assertSame([
            'status' => 'not_found',
            'reason' => 'CAPTURE_NOT_FOUND',
            'capture_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'retry' => ['eligible' => false, 'reason' => 'CAPTURE_NOT_FOUND'],
        ], $read->captureGet('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
    }

    public function test_capture_review_projection_exposes_reason_and_subject_continuation(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $record = new CaptureRecord(
            $id,
            'capture-review-reason',
            hash('sha256', 'capture-review-reason'),
            'INTERPRETED',
            'REVIEW_REQUIRED',
            null,
            null,
            [],
            ['content_intent' => ['intent' => 'IMAGE_ARTICLE']],
            [
                'content_preparation' => [
                    'status' => 'REVIEW_REQUIRED',
                    'review_reasons' => ['PRIMARY_SUBJECT_AMBIGUOUS'],
                    'candidates' => [['value' => 'Candidate A', 'source' => 'explicit_subject_hint']],
                    'continuation_decision' => ['may_continue' => false, 'reason' => 'BLOCKING_DEPENDENCY_REQUIRES_REVIEW'],
                ],
                'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => []],
            ],
        );
        $repository = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $repository,
        );

        $projection = $read->captureGet($id);

        self::assertSame(['PRIMARY_SUBJECT_AMBIGUOUS'], $projection['review']['reasons']);
        self::assertSame(['Candidate A'], array_column($projection['review']['candidates'], 'value'));
        self::assertSame('nhk.capture.ingest', $projection['review']['continuation']['entrypoint']);
        self::assertSame('subject_reconciliation', $projection['review']['continuation']['input']);
    }

    public function test_capture_read_retry_projection_matches_shared_executor_gate_for_non_resumable_block(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $record = new CaptureRecord($id, 'retry-parity', hash('sha256', 'retry-parity'), 'SEMANTICS_RECONCILED', 'SYSTEM_BLOCKED', diagnostics: [
            'completion' => ['status' => 'COMPLETE', 'children' => [['owner_type' => 'video', 'complete' => true, 'status' => 'COMPLETE']]],
            'resume_hints' => ['resume_children' => ['video']],
        ]);
        $repository = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $repository,
        );

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED', 'capture_id' => $id], $read->captureGet($id)['retry']);
    }

    public function test_capture_get_exposes_current_overlap_review_candidates_and_reason(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $record = new CaptureRecord($id, 'current-overlap', hash('sha256', 'current-overlap'), 'SEMANTICS_RECONCILED', 'REVIEW_REQUIRED', null, null, [], [], [
            'failure' => ['code' => 'SUBSTANTIAL_OVERLAP'],
            'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['SUBSTANTIAL_OVERLAP']],
            'article_resolution' => ['research' => ['ready_for_draft' => true, 'blockers' => [], 'overlap_analysis' => ['classification' => 'SUBSTANTIAL_OVERLAP', 'reason' => 'same intent', 'candidates' => [['article_id' => 902, 'classification' => 'SAME_INTENT', 'reason' => 'same persisted editorial intent']]]]],
        ], [
            'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'SUBSTANTIAL_OVERLAP', 'current_outcome' => 'CURRENT'],
        ]);
        $repository = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), captures: $repository,
        );

        $projection = $read->captureGet($id);

        self::assertSame(['SUBSTANTIAL_OVERLAP'], $projection['blockers']);
        self::assertTrue($projection['review']['current']);
        self::assertSame('SUBSTANTIAL_OVERLAP', $projection['review']['failure_code']);
        self::assertSame([902], array_column($projection['review']['candidates'], 'article_id'));
        self::assertSame(['eligible' => false, 'reason' => 'CURRENT_REVIEW_REQUIRED', 'capture_id' => $id], $projection['retry']);
    }
}
