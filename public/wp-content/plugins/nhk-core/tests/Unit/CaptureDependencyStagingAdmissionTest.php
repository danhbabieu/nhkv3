<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\CaptureDependencyStagingAdmission;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureDependencyStagingAdmissionTest extends TestCase
{
    /** @dataProvider subjectTypeProvider */
    public function test_video_dependency_uses_content_intent_not_capture_purpose(string $subjectType): void
    {
        $captureId = UuidCodec::newV7();
        $fingerprint = hash('sha256', 'capture-' . $subjectType);
        $capture = new CaptureRecord($captureId, 'video-' . $subjectType, $fingerprint, 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: [
            'purpose' => 'EDITORIAL',
            'content_intent' => ['intent' => 'VIDEO'],
        ], revision: 1);
        $scope = [
            'approved' => true,
            'environment' => 'staging',
            'semantic_write_policy' => 'PROJECT_BUILD',
            'entrypoint' => 'nhk.capture.ingest',
            'capture_id' => $captureId,
            'capture_fingerprint' => $fingerprint,
            'operation_family' => 'source_evidence_reconciliation',
            'entity_type' => 'source',
            'operation' => 'ingest',
            'plan_fingerprint' => hash('sha256', 'plan-' . $subjectType),
            'proposal_command_fingerprint' => hash('sha256', 'command-' . $subjectType),
            'payload_fingerprint' => hash('sha256', 'payload-' . $subjectType),
            'capture_revision' => 1,
            'expected_revision' => 0,
        ];

        $admission = new CaptureDependencyStagingAdmission();
        self::assertTrue($admission(false, $scope, $capture, ['intent' => 'VIDEO'], []));
        self::assertSame('ADMITTED', $admission->reason());
    }

    public function test_non_video_capture_intent_is_rejected_with_internal_reason(): void
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'article', hash('sha256', 'article'), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: [
            'purpose' => 'EDITORIAL',
            'content_intent' => ['intent' => 'TEXT_ARTICLE'],
        ]);
        $admission = new CaptureDependencyStagingAdmission();

        self::assertFalse($admission(false, [], $capture, [], []));
        self::assertSame('CAPTURE_CONTENT_INTENT_NOT_VIDEO', $admission->reason());
    }

    public function test_knowledge_delta_capture_can_admit_governed_dependency_child(): void
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'knowledge', hash('sha256', 'knowledge'), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: [
            'purpose' => 'EDITORIAL',
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
        ], revision: 1);
        $scope = [
            'approved' => true,
            'environment' => 'staging',
            'semantic_write_policy' => 'PROJECT_BUILD',
            'entrypoint' => 'nhk.capture.ingest',
            'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint,
            'operation_family' => 'knowledge_delta',
            'entity_type' => 'knowledge',
            'operation' => 'ingest',
            'plan_fingerprint' => str_repeat('a', 64),
            'proposal_command_fingerprint' => str_repeat('b', 64),
            'payload_fingerprint' => str_repeat('c', 64),
            'capture_revision' => 1,
            'expected_revision' => 0,
        ];
        $admission = new CaptureDependencyStagingAdmission();

        self::assertTrue($admission(false, $scope, $capture, [], []));
        self::assertSame('ADMITTED', $admission->reason());
    }

    /** @return iterable<string,array{0:string}> */
    public static function subjectTypeProvider(): iterable
    {
        yield 'classification' => ['classification'];
        yield 'variant' => ['variant'];
    }
}
