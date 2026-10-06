<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{CaptureCurrentOutcomeReducer, CaptureDecisionDependencyFingerprint, CapturePhaseReceiptReducer};
use NHK\Core\Domain\Capture\{CaptureRecord, CaptureStage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureCurrentOutcomeReducerTest extends TestCase
{
    public function test_failed_retryable_x_then_success_makes_x_historical_only(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));

        $reconciled = CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, $receipts);
        self::assertSame([], $reconciled['completion']['blockers']);
        self::assertSame('ERROR_X', $reconciled['failure_history'][0]['code']);
    }

    public function test_failed_retryable_x_then_x_keeps_x_current(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['ERROR_X'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('ERROR_X', CaptureCurrentOutcomeReducer::failureCode($capture));
        self::assertArrayNotHasKey('failure_history', CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, $receipts));
    }

    public function test_failed_retryable_x_then_y_uses_y_current_and_preserves_x_history(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_Y',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['ERROR_Y'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('ERROR_Y', CaptureCurrentOutcomeReducer::failureCode($capture));

        $reconciled = CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, $receipts);
        self::assertSame(['ERROR_Y'], $reconciled['completion']['blockers']);
        self::assertSame('ERROR_X', $reconciled['failure_history'][0]['code']);
    }

    public function test_later_successful_phase_does_not_resurrect_superseded_failure(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_Y', [
            'status' => 'COMPLETED', 'result' => 'VERIFIED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_current_owner_review_remains_blocking_after_historical_failure_is_superseded(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'OWNER_REVIEW', [
            'status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'OWNER_REVIEW_REQUIRED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X', 'OWNER_REVIEW_REQUIRED']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['OWNER_REVIEW_REQUIRED'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('OWNER_REVIEW_REQUIRED', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_current_system_blocked_failure_remains_authoritative(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'SYSTEM_GATE', [
            'status' => 'BLOCKED', 'result' => 'BLOCKED', 'failure_code' => 'SYSTEM_BLOCKED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X', 'SYSTEM_BLOCKED']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['SYSTEM_BLOCKED'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('SYSTEM_BLOCKED', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_verified_latest_phase_clears_historical_failure_from_current_code(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'current-outcome', hash('sha256', 'current-outcome'), CaptureStage::READY_FOR_PUBLICATION->value, 'COMPLETE', 123, 'state-current', [], [],
            ['failure_history' => [['code' => 'MEDIA_ASSET_STORAGE_KEY_IS_ALREADY_BOUND_TO_DIFFERENT_CONTENT_']], 'completion' => ['complete' => true, 'blockers' => []]],
            ['MEDIA_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED', 'latest' => ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED']]],
        );

        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));
        self::assertSame('MEDIA_ASSET_STORAGE_KEY_IS_ALREADY_BOUND_TO_DIFFERENT_CONTENT_', $capture->diagnostics['failure_history'][0]['code']);
    }

    public function test_current_failed_required_phase_remains_the_current_code(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'current-failure', hash('sha256', 'current-failure'), CaptureStage::SEMANTICS_RECONCILED->value, 'PARTIAL', null, null, [], [],
            ['completion' => ['complete' => false, 'blockers' => []]],
            ['SEMANTICS_RECONCILED' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'latest' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'SEMANTIC_READBACK_UNAVAILABLE']]],
        );

        self::assertSame('SEMANTIC_READBACK_UNAVAILABLE', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_historical_failure_is_not_current_after_new_phase_attempt_completes(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'historical-then-complete', hash('sha256', 'historical-then-complete'), CaptureStage::SEMANTICS_RECONCILED->value, 'COMPLETE', null, null, [], [],
            ['completion' => ['complete' => true, 'blockers' => []]],
            ['CONTENT_PREPARATION' => [
                'status' => 'COMPLETED',
                'result' => 'VERIFIED',
                'latest' => ['status' => 'COMPLETED', 'result' => 'VERIFIED', 'current_outcome' => 'CURRENT'],
                'attempts' => [
                    ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED'],
                    ['status' => 'COMPLETED', 'result' => 'VERIFIED'],
                ],
            ]],
        );

        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_unresolved_video_category_is_not_retryable_when_only_owner_is_missing(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'category-review', hash('sha256', 'category-review'), CaptureStage::SEMANTICS_RECONCILED->value, 'REVIEW_REQUIRED', null, null, [], [],
            ['completion' => [
                'status' => 'REVIEW_REQUIRED',
                'blockers' => ['CATEGORY_UNRESOLVED'],
                'missing_required_owners' => [['owner_type' => 'video', 'owner_id' => UuidCodec::newV7()]],
                'resume_hints' => ['resume_children' => ['video']],
            ]],
            ['VIDEO_GOVERNANCE' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CATEGORY_UNRESOLVED']]],
        );

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'], CaptureCurrentOutcomeReducer::retryEligibility($capture, ['resume_children' => ['video']]));
    }

    public function test_legacy_review_without_dependency_fingerprint_gets_one_bounded_reevaluation(): void
    {
        $capture = $this->reviewCapture(['content_preparation' => ['quality_decision' => 'READY']]);

        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_review_with_same_dependency_fingerprint_is_active_and_not_retryable(): void
    {
        $capture = $this->reviewCapture(['content_preparation' => ['quality_decision' => 'READY']]);
        $fingerprint = CaptureDecisionDependencyFingerprint::current($capture);
        $persisted = new CaptureRecord(
            $capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status,
            $capture->articleId, $capture->articleStateToken, $capture->assets, $capture->context,
            $capture->diagnostics + ['decision_dependency_fingerprint' => $fingerprint], $capture->phaseReceipts,
        );

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'], CaptureCurrentOutcomeReducer::retryEligibility($persisted));
    }

    public function test_changed_canonical_dependency_revision_reopens_review(): void
    {
        $capture = $this->reviewCapture([
            'canonical_context' => [['id' => 'claim-1', 'revision' => 1]],
            'content_preparation' => ['quality_decision' => 'READY'],
        ]);
        $fingerprint = CaptureDecisionDependencyFingerprint::current($capture);
        $changed = new CaptureRecord(
            $capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status,
            $capture->articleId, $capture->articleStateToken, $capture->assets,
            $capture->context + ['canonical_context' => [['id' => 'claim-1', 'revision' => 2]]],
            $capture->diagnostics + ['decision_dependency_fingerprint' => $fingerprint], $capture->phaseReceipts,
        );

        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($changed));
    }

    public function test_hard_block_review_cannot_be_reopened_by_dependency_change(): void
    {
        $capture = $this->reviewCapture([
            'content_preparation' => ['quality_decision' => 'HARD_BLOCK', 'blockers' => ['HARD_BLOCK']],
        ]);

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    /** @param array<string,mixed> $diagnosticParts */
    private function reviewCapture(array $diagnosticParts): CaptureRecord
    {
        return new CaptureRecord(
            UuidCodec::newV7(), 'review-' . bin2hex(random_bytes(3)), hash('sha256', 'review-' . bin2hex(random_bytes(3))),
            CaptureStage::SEMANTICS_RECONCILED->value, 'REVIEW_REQUIRED', null, null, [],
            ['raw_input' => 'Short subject input.', 'content_intent' => ['intent' => 'VIDEO']],
            array_replace_recursive([
                'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => [], 'resume_hints' => []],
            ], $diagnosticParts),
            ['CONTENT_PREPARATION' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED']]],
        );
    }

    /** @param array<string,mixed> $diagnostics @param array<string,mixed> $receipts */
    private function capture(array $diagnostics, array $receipts): CaptureRecord
    {
        return new CaptureRecord(
            UuidCodec::newV7(), 'current-state', hash('sha256', 'current-state'),
            CaptureStage::SEMANTICS_RECONCILED->value, 'PARTIAL', null, null, [], [],
            $diagnostics, $receipts,
        );
    }
}
