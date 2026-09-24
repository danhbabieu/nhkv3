<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\{GovernanceService, StagingAcceptanceScopeVerifier};
use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\AuthorityProposalExecutor;
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Application\Capture\CaptureVideoProvenancePlanner;
use NHK\Core\Application\Video\{VideoCompletenessPolicy, VideoRelationCandidatePlanner};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class VideoGovernanceGenericityTest extends TestCase
{
    public function test_sparse_editorial_review_does_not_block_governed_video_owner_write(): void
    {
        $videoId = UuidCodec::newV7();
        $videos = new class implements VideoRepository {
            /** @var array<string,Video> */
            public array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video
            {
                foreach ($this->items as $video) if ($video->platform === $platform && $video->externalVideoId === $externalId) return $video;
                return null;
            }
            public function create(Video $video): Video { return $this->items[$video->canonicalId] = $video; }
            public function update(Video $video, int $expectedRevision): Video { return $this->items[$video->canonicalId] = $video; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $metadata = [
            'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true, 'external_video_id' => 'dQw4w9WgXcQ', 'platform' => 'youtube'],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'Video an toàn', 'summary' => 'Tóm tắt tối thiểu', 'body' => 'Nội dung tối thiểu trong phạm vi nguồn.'],
            'content_quality' => ['status' => 'CONTENT_NEEDS_REVIEW'],
            'category' => ['primary' => null],
            'semantic_attachments' => [],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'Video an toàn', 'description' => 'Tóm tắt tối thiểu'],
        ];
        $proposal = new Proposal($videoId, $videoId, 'ingest', [
            'canonical_id' => $videoId,
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'title' => 'Video an toàn',
            'metadata' => $metadata,
        ], 'content', null, 'dependencies', ProposalState::APPROVED, idempotencyKey: 'sparse-video-owner', entityType: 'video');

        $result = (new AuthorityProposalExecutor(
            new AuthorityService(new \NHK\Tests\Support\InMemoryAuthorityRepository(), new \NHK\Core\Domain\Authority\EntityTypeRegistry()),
            video: new VideoService($videos),
        ))($proposal);

        self::assertInstanceOf(Video::class, $result);
        self::assertSame($videoId, $videos->findByCanonicalId($videoId)?->canonicalId);
        self::assertContains('CONTENT_NEEDS_REVIEW', $result->metadata['completeness']['blockers']);
        self::assertContains('NO_SEMANTIC_ATTACHMENT', $result->metadata['completeness']['blockers']);
    }

    public function test_owner_level_source_identity_blocker_still_prevents_video_write(): void
    {
        $videoId = UuidCodec::newV7();
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { throw new \LogicException('owner blocker must prevent create'); }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $proposal = new Proposal($videoId, $videoId, 'ingest', [
            'canonical_id' => $videoId,
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'title' => 'Video không hợp lệ',
            'metadata' => [
                'source' => ['identity_valid' => false, 'availability' => 'available', 'embeddable' => true, 'external_video_id' => 'dQw4w9WgXcQ', 'platform' => 'youtube'],
                'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
                'editorial' => ['title' => 'Video', 'summary' => 'Tóm tắt', 'body' => 'Nội dung'],
                'semantic_attachments' => [],
            ],
        ], 'content', null, 'dependencies', ProposalState::APPROVED, idempotencyKey: 'blocked-video-owner', entityType: 'video');

        $this->expectExceptionMessage('VIDEO_COMPLETENESS_BLOCKED:INVALID_SOURCE_IDENTITY');
        (new AuthorityProposalExecutor(
            new AuthorityService(new \NHK\Tests\Support\InMemoryAuthorityRepository(), new \NHK\Core\Domain\Authority\EntityTypeRegistry()),
            video: new VideoService($videos),
        ))($proposal);
    }

    public function test_variant_and_model_about_attachments_are_signed_from_final_payload(): void
    {
        foreach (['variant', 'model'] as $targetType) {
            $videoId = UuidCodec::newV7();
            $targetId = UuidCodec::newV7();
            $evidenceId = UuidCodec::newV7();
            $captureId = UuidCodec::newV7();
            $payload = $this->payload($videoId, $targetType, $targetId, $evidenceId);
            $verifier = $this->verifier();
            $scope = $verifier->issueForVideoPlan($this->capture($captureId), [
                'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
                'proposed_uuid' => $videoId, 'payload' => $payload,
                'idempotency_key' => 'generic-video-' . $targetType,
                'plan_fingerprint' => hash('sha256', $targetType),
            ]);
            $proposal = new Proposal(
                UuidCodec::newV7(), $videoId, 'ingest', $payload + [
                    'capture_id' => $captureId,
                    'capture_fingerprint' => $this->capture($captureId)->requestFingerprint,
                    'staging_acceptance' => $scope,
                ], 'content', null, 'dependency', ProposalState::APPROVED,
                idempotencyKey: 'generic-video-' . $targetType, entityType: 'video',
            );

            self::assertTrue($verifier->verifyProposal($scope, $proposal));
            self::assertSame([], $verifier->proposalDescriptorDiagnostic($scope, $proposal)['DESCRIPTOR_DIFF']);
            self::assertSame($payload['metadata']['semantic_attachments'][0]['target_uuid'], $proposal->payload['metadata']['semantic_attachments'][0]['target_uuid']);
        }
    }

    public function test_nested_semantic_payload_diagnostic_is_bounded_and_round_trips_thumbnail_owner(): void
    {
        $videoId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $capture = $this->capture(UuidCodec::newV7());
        $payload = $this->payload($videoId, 'variant', $targetId, $evidenceId);
        $payload['metadata']['source']['thumbnail_selection'] = ['variant' => 'maxresdefault', 'url' => 'https://img.youtube.test/maxresdefault.jpg', 'width' => 1920, 'height' => 1080];
        $payload['metadata']['seo_projection'] = ['open_graph' => ['image' => 'https://img.youtube.test/maxresdefault.jpg'], 'video_object' => ['thumbnailUrl' => ['https://img.youtube.test/maxresdefault.jpg']]];
        $verifier = $this->verifier();
        $scope = $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'proposed_uuid' => $videoId, 'payload' => $payload, 'idempotency_key' => 'nested-diagnostic', 'plan_fingerprint' => hash('sha256', 'nested-diagnostic')]);
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $payload + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $scope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'nested-diagnostic', entityType: 'video');
        $diagnostic = $verifier->proposalDescriptorDiagnostic($scope, $proposal);

        self::assertTrue($verifier->verifyProposal($scope, $proposal));
        self::assertSame([], $diagnostic['DESCRIPTOR_DIFF']);
        self::assertSame([], $diagnostic['PAYLOAD_DIFF']);
        self::assertSame($diagnostic['SIGNED_NORMALIZED_SEMANTIC_PAYLOAD']['tree_hash'], $diagnostic['VERIFIED_NORMALIZED_SEMANTIC_PAYLOAD']['tree_hash']);
        self::assertLessThanOrEqual(128, count($diagnostic['SIGNED_NORMALIZED_SEMANTIC_PAYLOAD']['fields']));
        self::assertArrayNotHasKey('thumbnail_selection', $this->applyThumbnailSelection($payload)['payload']['metadata']);

        $duplicate = $payload;
        $duplicate['metadata']['thumbnail_selection'] = $payload['metadata']['source']['thumbnail_selection'];
        $duplicateProposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $duplicate + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $scope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'nested-thumbnail-duplicate', entityType: 'video');
        $duplicateDiagnostic = $verifier->proposalDescriptorDiagnostic($scope, $duplicateProposal);
        self::assertSame('STAGING_VIDEO_PAYLOAD_MISMATCH', $verifier->proposalFailureReason($scope, $duplicateProposal));
        self::assertNotEmpty(array_filter(array_column($duplicateDiagnostic['PAYLOAD_DIFF'], 'path'), static fn (string $path): bool => str_starts_with($path, 'metadata.thumbnail_selection')));
    }

    public function test_nested_semantic_payload_diff_reports_exact_path_without_private_values(): void
    {
        $videoId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $capture = $this->capture(UuidCodec::newV7());
        $payload = $this->payload($videoId, 'variant', $targetId, $evidenceId);
        $verifier = $this->verifier();
        $scope = $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'proposed_uuid' => $videoId, 'payload' => $payload, 'idempotency_key' => 'nested-tamper', 'plan_fingerprint' => hash('sha256', 'nested-tamper')]);
        $tampered = $payload;
        $tampered['metadata']['semantic_attachments'][0]['target_uuid'] = UuidCodec::newV7();
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $tampered + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $scope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'nested-tamper', entityType: 'video');
        $diagnostic = $verifier->proposalDescriptorDiagnostic($scope, $proposal);
        $paths = array_column($diagnostic['PAYLOAD_DIFF'], 'path');

        self::assertSame('STAGING_VIDEO_PAYLOAD_MISMATCH', $verifier->proposalFailureReason($scope, $proposal));
        self::assertContains('metadata.semantic_attachments.0.target_uuid', $paths);
        self::assertArrayNotHasKey('signed', $diagnostic['PAYLOAD_DIFF'][0]);
        self::assertArrayNotHasKey('verified', $diagnostic['PAYLOAD_DIFF'][0]);
    }

    public function test_confidence_one_and_fractional_confidence_survive_json_reload_but_fractional_tamper_fails(): void
    {
        $videoId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $capture = $this->capture(UuidCodec::newV7());
        $verifier = $this->verifier();
        $payload = $this->payload($videoId, 'variant', $targetId, $evidenceId);
        $scope = $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'proposed_uuid' => $videoId, 'payload' => $payload, 'idempotency_key' => 'confidence-integral', 'plan_fingerprint' => hash('sha256', 'confidence-integral')]);
        $reloaded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $reloaded + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $scope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'confidence-integral', entityType: 'video');
        self::assertSame(1, $proposal->payload['metadata']['semantic_attachments'][0]['confidence']);
        self::assertTrue($verifier->verifyProposal($scope, $proposal));

        $fractionalPayload = $payload;
        $fractionalPayload['metadata']['semantic_attachments'][0]['confidence'] = 0.95;
        $fractionalScope = $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'proposed_uuid' => $videoId, 'payload' => $fractionalPayload, 'idempotency_key' => 'confidence-fractional', 'plan_fingerprint' => hash('sha256', 'confidence-fractional')]);
        $fractionalReloaded = json_decode(json_encode($fractionalPayload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $fractionalProposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $fractionalReloaded + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $fractionalScope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'confidence-fractional', entityType: 'video');
        self::assertSame(0.95, $fractionalProposal->payload['metadata']['semantic_attachments'][0]['confidence']);
        self::assertTrue($verifier->verifyProposal($fractionalScope, $fractionalProposal));

        $tampered = $fractionalReloaded;
        $tampered['metadata']['semantic_attachments'][0]['confidence'] = 0.90;
        $tamperedProposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $tampered + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $fractionalScope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'confidence-tampered', entityType: 'video');
        self::assertSame('STAGING_VIDEO_PAYLOAD_MISMATCH', $verifier->proposalFailureReason($fractionalScope, $tamperedProposal));
    }

    public function test_zero_attachment_does_not_invent_relation_and_keeps_blocker(): void
    {
        $result = (new VideoCompletenessPolicy())->evaluate([
            'source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'T', 'summary' => 'S', 'body' => 'B'],
            'category' => ['primary' => ['key' => 'category']],
            'semantic_attachments' => [],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'T', 'description' => 'S'],
        ]);

        self::assertContains('NO_SEMANTIC_ATTACHMENT', $result->blockers);
        self::assertNotContains('INVALID_EMBED_URL', $result->blockers);
    }

    public function test_video_completeness_rejects_non_youtube_embed_urls(): void
    {
        $result = (new VideoCompletenessPolicy())->ownerBlockers([
            'source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'T', 'summary' => 'S', 'body' => 'B'],
            'embed_url' => 'https://example.com/embed/dQw4w9WgXcQ',
        ]);

        self::assertContains('INVALID_EMBED_URL', $result);
    }

    public function test_multiple_about_attachments_are_deterministic_and_non_semantic_input_order(): void
    {
        $videoId = UuidCodec::newV7();
        $firstTarget = UuidCodec::newV7();
        $secondTarget = UuidCodec::newV7();
        $firstEvidence = UuidCodec::newV7();
        $secondEvidence = UuidCodec::newV7();
        $relations = [
            ['target_type' => 'model', 'target_id' => $secondTarget, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION', 'evidence_refs' => [['evidence_id' => $secondEvidence]], 'reason' => 'Canonical source provenance.', 'confidence' => 1.0],
            ['target_type' => 'variant', 'target_id' => $firstTarget, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION', 'evidence_refs' => [['evidence_id' => $firstEvidence]], 'reason' => 'Canonical source provenance.', 'confidence' => 1.0],
        ];
        $planner = $this->planner($firstEvidence, $secondEvidence);
        $a = array_map(static fn ($candidate): array => $candidate->toProposalPayload(), $planner->plan($videoId, $relations));
        $b = array_map(static fn ($candidate): array => $candidate->toProposalPayload(), $planner->plan($videoId, array_reverse($relations)));

        self::assertCount(2, $a);
        self::assertSame($a, $b);
        self::assertNotSame($a[0]['target_uuid'], $a[1]['target_uuid']);
    }

    public function test_target_predicate_and_evidence_tampering_fail_the_signed_final_command(): void
    {
        $videoId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $capture = $this->capture(UuidCodec::newV7());
        $payload = $this->payload($videoId, 'variant', $targetId, $evidenceId);
        $verifier = $this->verifier();
        $scope = $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'proposed_uuid' => $videoId, 'payload' => $payload, 'plan_fingerprint' => hash('sha256', 'tamper')]);

        foreach ([
            ['metadata' => ['semantic_attachments' => [['target_uuid' => UuidCodec::newV7()]]]],
            ['metadata' => ['semantic_attachments' => [['predicate' => 'depicts']]]],
            ['metadata' => ['semantic_attachments' => [['evidence_refs' => [['evidence_id' => UuidCodec::newV7()]]]]]],
        ] as $change) {
            $tampered = $payload;
            $tampered['metadata']['semantic_attachments'][0] = array_replace($tampered['metadata']['semantic_attachments'][0], $change['metadata']['semantic_attachments'][0]);
            $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $tampered + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'staging_acceptance' => $scope], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'tamper-' . UuidCodec::newV7(), entityType: 'video');
            self::assertSame('STAGING_VIDEO_PAYLOAD_MISMATCH', $verifier->proposalFailureReason($scope, $proposal));
        }
    }

    public function test_materialized_attachment_clears_only_stale_no_attachment_blocker(): void
    {
        $planner = new \NHK\Core\Application\Capture\CaptureVideoProvenancePlanner();
        $video = ['payload' => ['metadata' => ['completeness' => ['blockers' => ['CATEGORY_UNRESOLVED', 'NO_SEMANTIC_ATTACHMENT']]]]];
        $method = new \ReflectionMethod($planner, 'withAttachments');
        $method->setAccessible(true);
        $result = $method->invoke($planner, $video, [['predicate' => 'about', 'target_uuid' => UuidCodec::newV7()]]);

        self::assertSame(['CATEGORY_UNRESOLVED'], $result['payload']['metadata']['completeness']['blockers']);
    }

    public function test_reused_semantic_command_is_idempotent_without_duplicate_owner(): void
    {
        $videoId = UuidCodec::newV7();
        $payload = ['canonical_id' => $videoId, 'metadata' => ['semantic_attachments' => []]];
        $governance = new GovernanceService(new class implements \NHK\Core\Contracts\Governance\ProposalRepository {
            private array $items = [];
            public function create(Proposal $proposal): Proposal { return $this->items[$proposal->idempotencyKey] = $proposal; }
            public function find(string $id): ?Proposal { foreach ($this->items as $item) if ($item->id === $id) return $item; return null; }
            public function findByIdempotencyKey(string $key): ?Proposal { return $this->items[$key] ?? null; }
            public function save(Proposal $proposal): Proposal { return $this->items[$proposal->idempotencyKey] = $proposal; }
            public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
            public function recordApproval(Proposal $proposal, string $actor): void {}
            public function latestApproval(string $proposalId): ?array { return null; }
            public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
        });
        $first = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $payload, 'content', null, 'dependency', idempotencyKey: 'same-video-command', entityType: 'video');
        $second = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $payload, 'content', null, 'dependency', idempotencyKey: 'same-video-command', entityType: 'video');
        self::assertSame($first->id, $governance->create($first)->id);
        self::assertSame($first->id, $governance->create($second)->id);
    }

    private function payload(string $videoId, string $targetType, string $targetId, string $evidenceId): array
    {
        return ['canonical_id' => $videoId, 'metadata' => [
            'source' => ['platform' => 'youtube', 'external_video_id' => 'abcdefghijk', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abcdefghijk'],
            'subject_resolution_packet' => ['type' => $targetType, 'id' => $targetId, 'revision' => 1],
            'semantic_attachments' => [array_replace($this->relation($targetType, $targetId, $evidenceId), ['source_uuid' => $videoId])],
        ]];
    }

    private function relation(string $targetType, string $targetId, string $evidenceId): array
    {
        return ['source_type' => 'video', 'source_uuid' => '', 'target_type' => $targetType, 'target_uuid' => $targetId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION', 'evidence_refs' => [['evidence_id' => $evidenceId]], 'reason' => 'Canonical source provenance.', 'confidence' => 1.0];
    }

    private function capture(string $id): \NHK\Core\Domain\Capture\CaptureRecord
    {
        return new \NHK\Core\Domain\Capture\CaptureRecord($id, 'generic-video-' . $id, hash('sha256', $id), 'SEMANTICS_RECONCILED', 'IN_PROGRESS');
    }

    private function verifier(): StagingAcceptanceScopeVerifier
    {
        return new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true, can: static fn (): bool => true, videos: new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        });
    }

    private function planner(string ...$evidenceIds): VideoRelationCandidatePlanner
    {
        $evidence = new class($evidenceIds) implements EvidenceRepository {
            public function __construct(private array $ids) {}
            public function findByCanonicalId(string $id): ?Evidence { return in_array($id, $this->ids, true) ? new Evidence($id, UuidCodec::newV7(), UuidCodec::newV7(), 'supports', 'excerpt') : null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $claims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return UuidCodec::isValid($id) ? new KnowledgeClaim($id, 'generic:claim', 'Canonical claim') : null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $sources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return UuidCodec::isValid($id) ? new Source($id, 'generic:source', 'Canonical source', 'website', 'https://example.test/source') : null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        return new VideoRelationCandidatePlanner(new PredicateRegistry(), $evidence, $claims, $sources);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function applyThumbnailSelection(array $payload): array
    {
        $planner = new CaptureVideoProvenancePlanner();
        $method = new \ReflectionMethod($planner, 'withThumbnailSelection');
        $method->setAccessible(true);
        return $method->invoke($planner, ['payload' => $payload], $payload['metadata']['source']['thumbnail_selection']);
    }
}
