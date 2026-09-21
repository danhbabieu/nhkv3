<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\StagingOperationDescriptor;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Governance\CommandCanonicalizer;
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

    public function test_execution_envelope_is_not_self_referential_and_hashes_are_proven(): void
    {
        $captureId = UuidCodec::newV7();
        $captureFingerprint = hash('sha256', 'capture-envelope-proof');
        $videoId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $plan = [
            'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
            'proposed_uuid' => $videoId, 'idempotency_key' => 'envelope-proof',
            'payload' => [
                'canonical_id' => $videoId,
                'title' => 'Generated fixture video',
                'metadata' => [
                    'source' => ['platform' => 'youtube', 'external_video_id' => 'fixture12345', 'canonical_source_url' => 'https://www.youtube.com/watch?v=fixture12345'],
                    'subject_resolution_packet' => ['type' => 'variant', 'id' => $subjectId, 'revision' => 1],
                    'semantic_attachments' => [['predicate' => 'about', 'target_type' => 'variant', 'target_uuid' => $subjectId, 'evidence_refs' => [['evidence_id' => UuidCodec::newV7()]]]],
                ],
            ],
        ];
        $signed = self::descriptorFromPlan($plan, $captureId, $captureFingerprint);
        $envelopeA = ['approved' => true, 'issued_at' => '2026-09-21T01:00:00Z', 'expires_at' => '2026-09-21T01:15:00Z', 'fingerprint' => hash('sha256', 'scope-a'), 'signature' => hash('sha256', 'signature-a'), 'proposal_command_fingerprint' => $signed->payloadFingerprint];
        $envelopeB = array_replace($envelopeA, ['issued_at' => '2026-09-21T02:00:00Z', 'expires_at' => '2026-09-21T02:15:00Z', 'signature' => hash('sha256', 'signature-b')]);
        $persistedA = $signed->payload + ['staging_acceptance' => $envelopeA];
        $persistedB = $signed->payload + ['staging_acceptance' => $envelopeB];
        $hashA = $signed->payloadFingerprint;
        $hashB = hash('sha256', CommandCanonicalizer::canonicalize($persistedA));
        $hashC = hash('sha256', CommandCanonicalizer::canonicalize(StagingOperationDescriptor::normalizeSemanticPayload($persistedA, 'video')));
        $reloaded = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $persistedA, 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'envelope-proof', entityType: 'video');
        $verified = StagingOperationDescriptor::fromProposal($reloaded, ['capture_id' => $captureId, 'capture_fingerprint' => $captureFingerprint]);

        self::assertSame($hashA, $signed->payloadFingerprint, 'HASH_A must be the signed semantic command.');
        self::assertNotSame($hashA, $hashB, 'HASH_B must include the execution envelope and therefore differ.');
        self::assertSame($hashA, $hashC, 'HASH_C must remove only the execution envelope.');
        self::assertSame($hashA, $verified->payloadFingerprint, 'Reloaded semantic command must equal HASH_A.');
        self::assertSame($verified->payloadFingerprint, StagingOperationDescriptor::fromProposal(
            new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $persistedB, 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: 'envelope-proof', entityType: 'video'),
            ['capture_id' => $captureId, 'capture_fingerprint' => $captureFingerprint]
        )->payloadFingerprint, 'Different valid execution envelopes must not change the command fingerprint.');

        $mutated = $persistedA;
        $mutated['title'] = 'Semantic mutation';
        self::assertNotSame($hashA, hash('sha256', CommandCanonicalizer::canonicalize(StagingOperationDescriptor::normalizeSemanticPayload($mutated, 'video'))));
    }

    public function test_shared_envelope_normalizer_applies_to_non_video_relation_commands(): void
    {
        $captureId = UuidCodec::newV7();
        $captureFingerprint = hash('sha256', 'relation-envelope-proof');
        $sourceId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $plan = ['entity_type' => 'relation', 'operation' => 'relation_create', 'subject_id' => $sourceId, 'idempotency_key' => 'relation-envelope-proof', 'payload' => [
            'source_type' => 'video', 'source_uuid' => $sourceId, 'predicate' => 'about', 'target_type' => 'variant', 'target_uuid' => $targetId,
        ]];
        $signed = self::descriptorFromPlan($plan, $captureId, $captureFingerprint);
        $proposal = new Proposal(UuidCodec::newV7(), $sourceId, 'relation_create', $signed->payload + ['staging_acceptance' => ['issued_at' => 'one', 'signature' => 'one']], 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: $plan['idempotency_key'], entityType: 'relation');
        $verified = StagingOperationDescriptor::fromProposal($proposal, ['capture_id' => $captureId, 'capture_fingerprint' => $captureFingerprint]);

        self::assertSame('capture_child_relation', $signed->operationFamily);
        self::assertSame($signed->payloadFingerprint, $verified->payloadFingerprint);
    }

    public function test_video_ingest_zero_plan_and_null_reloaded_proposal_are_one_canonical_create_descriptor(): void
    {
        $captureId = UuidCodec::newV7();
        $captureFingerprint = hash('sha256', 'video-create-sentinel');
        $videoId = UuidCodec::newV7();
        $plan = [
            'entity_type' => 'video', 'operation' => 'ingest', 'subject_id' => $videoId,
            'expected_revision' => 0, 'idempotency_key' => 'video-create-sentinel',
            'payload' => ['canonical_id' => $videoId, 'dependency_ids' => [], 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'fixture12345']]],
        ];
        $signed = StagingOperationDescriptor::fromPlan($plan, $captureId, $captureFingerprint);
        $reloaded = new Proposal(UuidCodec::newV7(), $videoId, 'ingest', $signed->payload, 'content', null, 'dependency', ProposalState::APPROVED, idempotencyKey: $plan['idempotency_key'], entityType: 'video');
        $verified = StagingOperationDescriptor::fromProposal($reloaded, ['capture_id' => $captureId, 'capture_fingerprint' => $captureFingerprint]);

        self::assertSame(0, $signed->expectedRevision);
        self::assertNull($reloaded->expectedRevision);
        self::assertSame(0, $verified->expectedRevision);
        self::assertSame([], StagingOperationDescriptor::diff($signed, $verified));
    }

    public function test_video_update_revision_remains_exact_in_normalized_descriptor(): void
    {
        $captureId = UuidCodec::newV7();
        $captureFingerprint = hash('sha256', 'video-update-cas');
        $videoId = UuidCodec::newV7();
        $base = ['entity_type' => 'video', 'operation' => 'update', 'subject_id' => $videoId, 'target_uuid' => $videoId, 'idempotency_key' => 'video-update-cas', 'payload' => ['canonical_id' => $videoId]];
        $revision4 = StagingOperationDescriptor::fromPlan($base + ['expected_revision' => 4], $captureId, $captureFingerprint);
        $revision5 = StagingOperationDescriptor::fromPlan($base + ['expected_revision' => 5], $captureId, $captureFingerprint);

        self::assertSame(4, $revision4->expectedRevision);
        self::assertSame(5, $revision5->expectedRevision);
        self::assertNotSame($revision4->expectedRevision, $revision5->expectedRevision);
        self::assertNotSame($revision4->diagnosticValue()['expected_revision'], $revision5->diagnosticValue()['expected_revision']);
    }

    /** @param array<string,mixed> $plan */
    private static function descriptorFromPlan(array $plan, string $captureId, string $captureFingerprint): StagingOperationDescriptor
    {
        return StagingOperationDescriptor::fromPlan($plan, $captureId, $captureFingerprint);
    }
}
