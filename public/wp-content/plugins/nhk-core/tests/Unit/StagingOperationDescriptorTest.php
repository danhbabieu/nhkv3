<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\StagingOperationDescriptor;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class StagingOperationDescriptorTest extends TestCase
{
    /** @dataProvider semanticSubjectProvider */
    public function test_final_dependency_and_proposal_use_one_identity_for_optional_source_fields(string $subjectType): void
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'descriptor-' . $subjectType, hash('sha256', 'capture-' . $subjectType), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']]);
        $subject = UuidCodec::newV7();
        $plan = [
            'entity_type' => 'source', 'operation' => 'ingest', 'subject_id' => 'nhk:source:video:test',
            'idempotency_key' => 'source-' . $subjectType,
            'payload' => [
                'stable_key' => 'nhk:source:video:test', 'title' => 'Âm thanh đồng hồ', 'source_type' => 'website',
                'locator' => 'https://www.youtube.com/watch?v=example', 'metadata' => [
                    'platform' => 'youtube', 'external_video_id' => 'example', 'subject_id' => $subject,
                    'subject_type' => $subjectType, 'source_description' => null, 'caption_availability' => 'unavailable',
                    'transcript' => null,
                ],
            ],
        ];
        $issued = StagingOperationDescriptor::fromPlan($plan, $capture->captureId, $capture->requestFingerprint);
        $proposal = new Proposal(UuidCodec::newV7(), $plan['subject_id'], 'ingest', $issued->payload, 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: $plan['idempotency_key'], entityType: 'source');
        $verified = StagingOperationDescriptor::fromProposal($proposal, ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint]);

        self::assertSame('source_evidence_reconciliation', $issued->operationFamily);
        self::assertSame('ingest', $issued->createSemantics);
        self::assertSame(0, $issued->expectedRevision);
        self::assertSame($issued->payloadFingerprint, $verified->payloadFingerprint);
        self::assertSame($issued->operationFamily, $verified->operationFamily);
        self::assertSame($subjectType, $issued->payload['metadata']['subject_type']);
    }

    /** @return iterable<string,array{0:string}> */
    public static function semanticSubjectProvider(): iterable
    {
        yield 'classification' => ['classification'];
        yield 'variant' => ['variant'];
    }

    public function test_production_shaped_video_plan_and_reloaded_proposal_have_no_descriptor_diff(): void
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'video-descriptor', hash('sha256', 'video-descriptor'), 'SEMANTICS_RECONCILED', 'SYSTEM_BLOCKED', context: ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'VIDEO']]);
        $videoId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $dependencyIds = [UuidCodec::newV7(), UuidCodec::newV7(), UuidCodec::newV7()];
        $plan = [
            'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
            'idempotency_key' => 'video-descriptor-final', 'dependency_ids' => $dependencyIds,
            'payload' => [
                'canonical_id' => $videoId,
                'dependency_ids' => $dependencyIds,
                'capture_revision' => 42,
                'metadata' => [
                    'source' => [
                        'platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ',
                        'canonical_source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        'source_title' => 'Nguồn video', 'fetched_at' => '2026-09-21T01:00:00Z',
                        'source_hash' => str_repeat('a', 64),
                        'thumbnail_selection' => ['variant' => 'maxresdefault', 'url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg', 'width' => 1280, 'height' => 720, 'probed_at' => '2026-09-21T01:00:01Z', 'probe_hash' => str_repeat('b', 64)],
                    ],
                    'subject_resolution_packet' => ['status' => 'RESOLVED', 'match' => 'uuid_exact', 'type' => 'variant', 'id' => $subjectId, 'revision' => 2],
                    'semantic_attachments' => [['predicate' => 'about', 'target_type' => 'variant', 'target_uuid' => $subjectId, 'evidence_refs' => [['evidence_id' => $dependencyIds[2]]]]],
                ],
            ],
        ];
        $signed = StagingOperationDescriptor::fromPlan($plan, $capture->captureId, $capture->requestFingerprint);
        $proposal = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $signed->payload + ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint, 'capture_revision' => 42, 'staging_acceptance' => ['approved' => true]], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: $plan['idempotency_key'], entityType: 'video');
        $verified = StagingOperationDescriptor::fromProposal($proposal, ['capture_id' => $capture->captureId, 'capture_fingerprint' => $capture->requestFingerprint]);

        self::assertSame([], StagingOperationDescriptor::diff($signed, $verified), json_encode(StagingOperationDescriptor::diff($signed, $verified), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::assertSame($signed->payloadFingerprint, $verified->payloadFingerprint);
    }
}
