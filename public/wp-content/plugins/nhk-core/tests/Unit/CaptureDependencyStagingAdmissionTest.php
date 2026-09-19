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
        ]);
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

    /** @return iterable<string,array{0:string}> */
    public static function subjectTypeProvider(): iterable
    {
        yield 'classification' => ['classification'];
        yield 'variant' => ['variant'];
    }
}
