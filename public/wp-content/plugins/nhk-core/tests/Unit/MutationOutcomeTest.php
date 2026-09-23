<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Application\Capture\MutationOutcome;
use NHK\Core\Application\Capture\MutationOutcomeClassifier;
use NHK\Core\Application\Capture\UncertainMutationReconciler;
use PHPUnit\Framework\TestCase;

final class MutationOutcomeTest extends TestCase
{
    public function test_outcome_factories_expose_distinct_statuses(): void
    {
        self::assertSame('SUCCESS_WITH_READBACK', MutationOutcome::successWithReadback([
            'canonical_id' => 'video-1',
            'revision' => 2,
            'status' => 'OWNER_CREATED',
        ])->status());
        self::assertSame('FAILED_CONFIRMED', MutationOutcome::failedConfirmed('VALIDATION_FAILED')->status());
        self::assertSame('OUTCOME_UNKNOWN', MutationOutcome::unknown('EMPTY_RESPONSE')->status());
    }

    public function test_empty_success_payload_is_unknown_until_canonical_reconciliation(): void
    {
        $outcome = (new MutationOutcomeClassifier())->classify((object) [], [
            'dispatched' => true,
            'idempotency_key' => 'video-1',
        ]);

        self::assertSame('OUTCOME_UNKNOWN', $outcome->status());
        self::assertSame('video-1', $outcome->identity()['idempotency_key']);
    }

    public function test_malformed_response_is_not_confirmed_failure(): void
    {
        $outcome = (new MutationOutcomeClassifier())->classify('not-json', [
            'dispatched' => true,
            'idempotency_key' => 'video-2',
        ]);

        self::assertSame('OUTCOME_UNKNOWN', $outcome->status());
        self::assertSame('MALFORMED_RESPONSE', $outcome->reason());
    }

    public function test_reconciliation_reuses_existing_capture_and_video_without_replay(): void
    {
        $result = (new UncertainMutationReconciler())->reconcile(
            ['idempotency_key' => 'video-3', 'request_fingerprint' => 'fp-3'],
            static fn (array $identity): array => ['capture_id' => 'capture-3'],
            static fn (array $identity): array => ['canonical_id' => 'video-3', 'revision' => 4],
            static fn (array $identity): array => ['canonical_id' => 'video-3'],
        );

        self::assertSame('REUSE_AND_RESUME', $result['status']);
        self::assertSame('video-3', $result['video']['canonical_id']);
        self::assertSame('video-3', $result['identity']['idempotency_key']);
    }

    public function test_reconciliation_replays_same_identity_only_when_no_owner_exists(): void
    {
        $result = (new UncertainMutationReconciler())->reconcile(
            ['idempotency_key' => 'video-4', 'request_fingerprint' => 'fp-4'],
            static fn (array $identity): ?array => null,
            static fn (array $identity): ?array => null,
            static fn (array $identity): ?array => null,
        );

        self::assertSame('REPLAY_SAME_IDENTITY', $result['status']);
        self::assertSame('video-4', $result['identity']['idempotency_key']);
    }
}
