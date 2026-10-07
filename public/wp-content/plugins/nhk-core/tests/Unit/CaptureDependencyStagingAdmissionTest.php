<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\CaptureDependencyStagingAdmission;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureDependencyStagingAdmissionTest extends TestCase
{
    /** @dataProvider reactivationEntityProvider */
    public function test_reactivate_is_admitted_for_capture_bound_dependency_with_existing_revision(string $entityType, string $family): void
    {
        $capture = $this->capture('reactivate-' . $entityType);
        $scope = $this->scope($capture, $entityType, $family, 'reactivate', 2);

        $admission = new CaptureDependencyStagingAdmission();

        self::assertTrue($admission(false, $scope, $capture, [], []));
        self::assertSame('ADMITTED', $admission->reason());
    }

    /** @dataProvider reactivationEntityProvider */
    public function test_reactivate_with_zero_revision_is_rejected(string $entityType, string $family): void
    {
        $capture = $this->capture('reactivate-zero-' . $entityType);
        $scope = $this->scope($capture, $entityType, $family, 'reactivate', 0);
        $admission = new CaptureDependencyStagingAdmission();

        self::assertFalse($admission(false, $scope, $capture, [], []));
        self::assertSame('TARGET_REVISION_REQUIRED', $admission->reason());
    }

    public function test_reactivate_without_valid_capture_scope_is_rejected(): void
    {
        $capture = $this->capture('reactivate-invalid-scope');
        $scope = $this->scope($capture, 'knowledge', 'knowledge_delta', 'reactivate', 2);
        $scope['capture_id'] = UuidCodec::newV7();
        $admission = new CaptureDependencyStagingAdmission();

        self::assertFalse($admission(false, $scope, $capture, [], []));
        self::assertSame('DEPENDENCY_OPERATION_NOT_ALLOWED', $admission->reason());
    }

    public function test_unsupported_dependency_operation_remains_rejected(): void
    {
        $capture = $this->capture('unsupported-operation');
        $scope = $this->scope($capture, 'knowledge', 'knowledge_delta', 'delete', 2);
        $admission = new CaptureDependencyStagingAdmission();

        self::assertFalse($admission(false, $scope, $capture, [], []));
        self::assertSame('DEPENDENCY_OPERATION_NOT_ALLOWED', $admission->reason());
    }

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

    /** @return iterable<string,array{0:string,1:string}> */
    public static function reactivationEntityProvider(): iterable
    {
        yield 'knowledge' => ['knowledge', 'knowledge_delta'];
        yield 'source' => ['source', 'source_evidence_reconciliation'];
        yield 'evidence' => ['evidence', 'source_evidence_reconciliation'];
    }

    private function capture(string $key): CaptureRecord
    {
        return new CaptureRecord(UuidCodec::newV7(), $key, hash('sha256', $key), 'SEMANTICS_RECONCILED', 'IN_PROGRESS', context: [
            'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
        ], revision: 3);
    }

    /** @return array<string,mixed> */
    private function scope(CaptureRecord $capture, string $entityType, string $family, string $operation, int $expectedRevision): array
    {
        return [
            'approved' => true,
            'environment' => 'staging',
            'semantic_write_policy' => 'PROJECT_BUILD',
            'entrypoint' => 'nhk.capture.ingest',
            'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint,
            'operation_family' => $family,
            'entity_type' => $entityType,
            'operation' => $operation,
            'plan_fingerprint' => str_repeat('a', 64),
            'proposal_command_fingerprint' => str_repeat('b', 64),
            'payload_fingerprint' => str_repeat('c', 64),
            'capture_revision' => $capture->revision,
            'expected_revision' => $expectedRevision,
        ];
    }
}
