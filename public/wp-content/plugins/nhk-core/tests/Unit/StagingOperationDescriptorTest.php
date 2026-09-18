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
        $capture = new CaptureRecord(UuidCodec::newV7(), 'descriptor-' . $subjectType, hash('sha256', 'capture-' . $subjectType), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: ['purpose' => 'VIDEO']);
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
}
