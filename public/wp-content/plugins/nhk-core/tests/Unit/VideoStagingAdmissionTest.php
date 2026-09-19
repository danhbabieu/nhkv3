<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\VideoStagingAdmission;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use PHPUnit\Framework\TestCase;

final class VideoStagingAdmissionTest extends TestCase
{
    public function test_exact_w64_binding_is_admitted(): void
    {
        [$capture, $scope] = $this->fixture();
        self::assertTrue((new VideoStagingAdmission())(false, $scope, $capture, [], []));
    }

    public function test_capture_video_variant_operation_revision_and_fingerprint_are_exact(): void
    {
        [$capture, $scope] = $this->fixture();
        $cases = [
            ['capture_id', 'different'], ['target_uuid', 'different'], ['subject_id', 'different'],
            ['operation', 'create'], ['expected_revision', 6], ['capture_fingerprint', hash('sha256', 'wrong')],
        ];
        foreach ($cases as [$field, $value]) {
            $candidate = $scope;
            if ($field === 'subject_id') {
                $assets = $capture->assets;
                $assets[0]['video_proposal']['payload']['metadata']['subject_resolution_packet']['id'] = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
                $candidate = new CaptureRecord($capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status, $capture->articleId, $capture->articleStateToken, $assets, $capture->context, $capture->diagnostics, $capture->phaseReceipts, $capture->revision, $capture->createdAt, $capture->updatedAt);
                self::assertFalse((new VideoStagingAdmission())(false, $scope, $candidate, [], []));
                continue;
            }
            $candidate[$field] = $value;
            self::assertFalse((new VideoStagingAdmission())(false, $candidate, $capture, [], []), $field);
        }
    }

    public function test_unrelated_staging_package_and_production_are_rejected(): void
    {
        [$capture, $scope] = $this->fixture();
        $unrelated = $scope;
        $unrelated['target_uuid'] = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        self::assertFalse((new VideoStagingAdmission())(false, $unrelated, $capture, [], []));

        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'production', 'test-secret', new VideoStagingAdmission());
        $this->expectExceptionMessage('STAGING_PRODUCTION_FORBIDDEN');
        $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'update', 'target_uuid' => $scope['target_uuid'], 'expected_revision' => 5, 'fingerprint' => hash('sha256', 'plan')]);
    }

    public function test_generic_ingest_scope_is_admitted_for_a_video_capture(): void
    {
        $captureId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $videoId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $subjectId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $fingerprint = hash('sha256', 'generic-ingest');
        $capture = new CaptureRecord($captureId, 'generic-ingest', $fingerprint, 'SEMANTICS_RECONCILED', 'IN_PROGRESS', null, null, [[
            'kind' => 'video',
            'video_proposal' => ['payload' => [
                'canonical_id' => $videoId,
                'metadata' => [
                    'source' => ['platform' => 'youtube', 'external_video_id' => '2EMuIG2RfTg', 'canonical_source_url' => 'https://www.youtube.com/watch?v=2EMuIG2RfTg'],
                    'subject_resolution_packet' => ['status' => 'RESOLVED', 'type' => 'classification', 'id' => $subjectId, 'revision' => 1],
                ],
            ]],
        ]], ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']], [], []);
        $scope = [
            'approved' => true, 'environment' => 'staging', 'semantic_write_policy' => 'PROJECT_BUILD',
            'operation_family' => 'governed_video_plan', 'entity_type' => 'video', 'operation' => 'ingest',
            'create_semantics' => 'ingest', 'writer' => 'canonical_governed', 'entrypoint' => 'nhk.capture.ingest',
            'capture_id' => $captureId, 'capture_fingerprint' => $fingerprint, 'plan_fingerprint' => hash('sha256', 'plan'),
            'proposal_command_fingerprint' => hash('sha256', 'command'), 'platform' => 'youtube',
            'external_video_id' => '2EMuIG2RfTg', 'canonical_source_url' => 'https://www.youtube.com/watch?v=2EMuIG2RfTg',
            'proposed_uuid' => $videoId, 'subject' => ['type' => 'classification', 'uuid' => $subjectId, 'revision' => 1],
        ];

        self::assertTrue((new VideoStagingAdmission(new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        }))(false, $scope, $capture, [], []));
    }

    public function test_video_content_intent_is_admitted_when_capture_purpose_is_editorial(): void
    {
        $captureId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $videoId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $subjectId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $fingerprint = hash('sha256', 'editorial-video-intent');
        $capture = new CaptureRecord($captureId, 'editorial-video-intent', $fingerprint, 'SEMANTICS_RECONCILED', 'IN_PROGRESS', null, null, [[
            'kind' => 'video',
            'video_proposal' => ['payload' => ['canonical_id' => $videoId, 'metadata' => [
                'source' => ['platform' => 'youtube', 'external_video_id' => 'kdhFeE9bA6A', 'canonical_source_url' => 'https://www.youtube.com/watch?v=kdhFeE9bA6A'],
                'subject_resolution_packet' => ['status' => 'RESOLVED', 'type' => 'classification', 'id' => $subjectId, 'revision' => 3],
            ]]],
        ]], ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']], [], []);
        $scope = [
            'approved' => true, 'environment' => 'staging', 'semantic_write_policy' => 'PROJECT_BUILD',
            'operation_family' => 'governed_video_plan', 'entity_type' => 'video', 'operation' => 'ingest',
            'create_semantics' => 'ingest', 'writer' => 'canonical_governed', 'entrypoint' => 'nhk.capture.ingest',
            'capture_id' => $captureId, 'capture_fingerprint' => $fingerprint,
            'plan_fingerprint' => hash('sha256', 'plan'), 'proposal_command_fingerprint' => hash('sha256', 'command'),
            'platform' => 'youtube', 'external_video_id' => 'kdhFeE9bA6A',
            'canonical_source_url' => 'https://www.youtube.com/watch?v=kdhFeE9bA6A',
            'proposed_uuid' => $videoId, 'subject' => ['type' => 'classification', 'uuid' => $subjectId, 'revision' => 3],
        ];
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };

        self::assertTrue((new VideoStagingAdmission($videos))(false, $scope, $capture, [], []));
    }

    public function test_fresh_ingest_keeps_video_owner_subject_id_separate_from_resolved_semantic_subject(): void
    {
        $captureId = '01a0b2e0-1888-7038-9811-2dd7e7073a27';
        $videoId = '01a0b2e0-1ba4-751c-8fe1-98c1401319d9';
        $semanticSubjectId = '01a0a868-2918-7dac-81dc-bfc25e710068';
        $captureFingerprint = hash('sha256', 'live-shaped-video-capture');
        $capture = new CaptureRecord($captureId, 'video-live-shape', $captureFingerprint, 'SEMANTICS_RECONCILED', 'IN_PROGRESS', null, null, [[
            'kind' => 'video',
            'video_proposal' => [
                'entity_type' => 'video',
                'operation' => 'ingest',
                'subject_id' => $videoId,
                'payload' => [
                    'canonical_id' => $videoId,
                    'metadata' => [
                        'source' => [
                            'platform' => 'youtube',
                            'external_video_id' => '2EMuIG2RfTg',
                            'canonical_source_url' => 'https://www.youtube.com/watch?v=2EMuIG2RfTg',
                        ],
                        'subject_resolution_packet' => [
                            'status' => 'RESOLVED',
                            'type' => 'classification',
                            'id' => $semanticSubjectId,
                            'name' => 'Đồng hồ 400 ngày',
                            'stable_key' => 'nhk:classification:clock-type.dong-ho-400-ngay',
                            'revision' => 1,
                        ],
                    ],
                ],
            ],
        ]], ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']], [], []);

        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $verifier = new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging',
            'test-secret',
            static function (array $scope, CaptureRecord $capture, array $input, array $assets) use ($videos): bool {
                return (new VideoStagingAdmission($videos))(false, $scope, $capture, $input, $assets);
            },
            can: static fn (): bool => true,
            videos: $videos,
        );

        $scope = $verifier->issueForVideoPlan($capture, [
            'entity_type' => 'video',
            'operation' => 'ingest',
            'subject_id' => $videoId,
            'proposed_uuid' => $videoId,
            'payload' => $capture->assets[0]['video_proposal']['payload'],
            'fingerprint' => hash('sha256', 'live-shaped-video-plan'),
        ]);

        self::assertSame($videoId, $scope['proposed_uuid']);
        self::assertSame($semanticSubjectId, $scope['subject']['uuid']);
        self::assertSame('classification', $scope['subject']['type']);

        $proposal = new Proposal(
            '01a0b2e0-1ba4-751c-8fe1-98c1401319d0',
            $videoId,
            'ingest',
            [
                'capture_id' => $captureId,
                'capture_fingerprint' => $captureFingerprint,
                'canonical_id' => $videoId,
                'metadata' => $capture->assets[0]['video_proposal']['payload']['metadata'],
                'staging_acceptance' => $scope,
            ],
            'content',
            null,
            'dependency',
            ProposalState::APPROVED,
            idempotencyKey: 'video-live-shape',
            entityType: 'video',
        );

        self::assertTrue($verifier->verifyProposal($scope, $proposal));
    }

    public function test_reused_verified_dependencies_and_explicit_about_relation_pass_final_video_admission(): void
    {
        $captureId = '01a0b89f-9fd0-705e-a7bf-865ee7229bff';
        $videoId = '01a0b2e0-1ba4-751c-8fe1-98c1401319d9';
        $variantId = '5f6c98ca-869a-4418-a8a4-1a32eb931c5e';
        $sourceId = '01a0b2e0-1888-7038-9811-2dd7e7073a27';
        $claimId = '01a0b2e0-1888-7038-9811-2dd7e7073a28';
        $evidenceId = '01a0b2e0-1888-7038-9811-2dd7e7073a29';
        $capture = new CaptureRecord($captureId, 'video-reused-verified', hash('sha256', 'video-reused-verified'), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', null, null, [], ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']], [], [], 7);
        $payload = [
            'canonical_id' => $videoId,
            'metadata' => [
                'source' => ['platform' => 'youtube', 'external_video_id' => 'TA2haJAn3EM', 'canonical_source_url' => 'https://www.youtube.com/watch?v=TA2haJAn3EM'],
                'subject_resolution_packet' => ['status' => 'RESOLVED', 'type' => 'variant', 'id' => $variantId, 'revision' => 1],
                'semantic_attachments' => [[
                    'predicate' => 'about', 'target_type' => 'variant', 'target_uuid' => $variantId,
                    'origin' => 'EXPLICIT_USER_RELATION', 'evidence_refs' => [['evidence_id' => $evidenceId]],
                ]],
            ],
            'dependency_ids' => [$sourceId, $claimId, $evidenceId],
        ];
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $verifier = new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging', 'test-secret',
            static fn (array $scope, CaptureRecord $capture, array $input, array $assets): bool => (new VideoStagingAdmission($videos))(false, $scope, $capture, $input, $assets),
            can: static fn (): bool => true, videos: $videos,
        );
        $scope = $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId, 'proposed_uuid' => $videoId, 'dependency_ids' => $payload['dependency_ids'], 'payload' => $payload, 'plan_fingerprint' => hash('sha256', 'final-video-plan')]);
        $proposalPayload = $payload + ['capture_id' => $captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'capture_revision' => $capture->revision, 'staging_acceptance' => $scope];
        $proposal = new Proposal('01a0b2e0-1ba4-751c-8fe1-98c1401319d0', $videoId, 'ingest', $proposalPayload, 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'video-reused-verified', entityType: 'video');

        self::assertSame($capture->revision, $scope['capture_revision']);
        self::assertSame($payload['dependency_ids'], $scope['dependency_ids']);
        self::assertTrue($verifier->verifyProposal($scope, $proposal));
    }

    /** @return array{0:CaptureRecord,1:array<string,mixed>} */
    private function fixture(): array
    {
        $captureId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $fingerprint = hash('sha256', 'generic-video');
        $videoId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $subjectId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $capture = new CaptureRecord($captureId, 'generic-video', $fingerprint, 'SEMANTICS_RECONCILED', 'FAILED_RETRYABLE', null, null, [[
            'kind' => 'video', 'video_proposal' => ['payload' => ['canonical_id' => $videoId, 'expected_revision' => 5, 'metadata' => ['subject_resolution_packet' => ['id' => $subjectId, 'type' => 'variant']]]],
        ]], ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']], [], []);
        return [$capture, [
            'approved' => true, 'environment' => 'staging', 'semantic_write_policy' => 'PROJECT_BUILD', 'operation_family' => 'governed_video_plan',
            'entity_type' => 'video', 'operation' => 'update', 'writer' => 'canonical_governed',
            'entrypoint' => 'nhk.capture.ingest', 'capture_id' => $captureId,
            'capture_fingerprint' => $fingerprint, 'target_uuid' => $videoId, 'subject_id' => $subjectId,
            'expected_revision' => 5, 'plan_fingerprint' => hash('sha256', 'plan'), 'proposal_command_fingerprint' => hash('sha256', 'command'),
        ]];
    }

}
