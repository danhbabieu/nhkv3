<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureAuthorityContinuationPolicy;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class CaptureAuthorityContinuationPolicyTest extends TestCase
{
    public function test_applied_mixed_capture_without_new_authority_delta_is_editorial_continuation(): void
    {
        self::assertSame(
            CaptureAuthorityContinuationPolicy::EDITORIAL_CONTINUATION,
            CaptureAuthorityContinuationPolicy::classify(['capture_id' => 'capture-1', 'purpose' => 'MIXED', 'text' => 'Bổ sung nội dung.']),
        );
    }

    public function test_new_editorial_capture_remains_on_the_editorial_entry_boundary(): void
    {
        self::assertSame(
            CaptureAuthorityContinuationPolicy::EDITORIAL_CONTINUATION,
            CaptureAuthorityContinuationPolicy::classify(['purpose' => 'EDITORIAL', 'text' => 'Bài editorial mới.']),
        );
    }

    public function test_new_authority_request_or_relationship_operation_remains_authority_mutation(): void
    {
        self::assertSame(
            CaptureAuthorityContinuationPolicy::AUTHORITY_MUTATION,
            CaptureAuthorityContinuationPolicy::classify(['capture_id' => 'capture-1', 'purpose' => 'MIXED', 'authority_intent' => ['mode' => 'PLAN', 'requests' => [['entity_type' => 'brand']]]]),
        );
        self::assertSame(
            CaptureAuthorityContinuationPolicy::AUTHORITY_MUTATION,
            CaptureAuthorityContinuationPolicy::classify(['capture_id' => 'capture-1', 'purpose' => 'MIXED', 'relationship_operations' => [['operation' => 'relation_create']]]),
        );
    }

    public function test_identical_applied_authority_packet_is_classified_as_replay(): void
    {
        $capture = new CaptureRecord(
            '01a10bdb-918d-7c6d-a94e-0dd4baa5f391',
            'key',
            hash('sha256', 'key'),
            'AUTHORITY_APPLIED',
            'APPLIED',
            null,
            null,
            [],
            [
                'purpose' => 'MIXED',
                'authority_result' => [
                    'approved_plan_fingerprint' => str_repeat('a', 64),
                    'approved_candidate_ids' => ['candidate-1'],
                    'result' => ['status' => 'APPLIED'],
                ],
            ],
        );

        self::assertSame(
            CaptureAuthorityContinuationPolicy::AUTHORITY_REPLAY,
            CaptureAuthorityContinuationPolicy::classify([
                'capture_id' => $capture->captureId,
                'authority_intent' => [
                    'mode' => 'APPLY_APPROVED_PLAN',
                    'approved_plan_fingerprint' => str_repeat('a', 64),
                    'approved_candidate_ids' => ['candidate-1'],
                ],
            ], $capture),
        );
    }
}
