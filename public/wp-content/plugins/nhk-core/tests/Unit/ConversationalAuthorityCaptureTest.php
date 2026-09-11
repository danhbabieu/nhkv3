<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CapturePurposePolicy;
use NHK\Core\Application\Capture\AuthorityCaptureService;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Capture\CapturePurpose;
use PHPUnit\Framework\TestCase;

final class ConversationalAuthorityCaptureTest extends TestCase
{
    public function test_legacy_editorial_packet_defaults_to_editorial(): void
    {
        self::assertSame(CapturePurpose::EDITORIAL, CapturePurposePolicy::resolve([]));
        self::assertSame(CapturePurpose::EDITORIAL, CapturePurposePolicy::resolve(['text' => 'Bài viết editorial.']));
    }

    public function test_authority_intent_requires_authority_or_mixed_purpose(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AUTHORITY_PURPOSE_REQUIRED');

        CapturePurposePolicy::resolve(['authority_intent' => ['mode' => 'PLAN'], 'text' => 'Tạo thương hiệu Hermle.']);
    }

    public function test_apply_packet_requires_an_existing_capture_continuation(): void
    {
        $service = new AuthorityCaptureService(new AuthorityCaptureRepository(), static fn (array $input, CaptureRecord $capture): array => []);

        $this->expectExceptionMessage('AUTHORITY_CONTINUATION_REQUIRED');
        $service->execute(['idempotency_key' => 'apply-without-capture', 'purpose' => 'AUTHORITY', 'text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'APPLY_APPROVED_PLAN', 'approved_plan_fingerprint' => str_repeat('a', 64), 'approved_candidate_ids' => ['candidate-hermle']]]);
    }

    public function test_editorial_purpose_with_authority_intent_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AUTHORITY_PURPOSE_CONFLICT');

        CapturePurposePolicy::resolve([
            'purpose' => 'EDITORIAL',
            'authority_intent' => ['mode' => 'PLAN'],
            'text' => 'Tạo thương hiệu Hermle.',
        ]);
    }

    public function test_authority_and_mixed_purpose_are_closed_values(): void
    {
        self::assertSame(CapturePurpose::AUTHORITY, CapturePurposePolicy::resolve([
            'purpose' => 'AUTHORITY',
            'authority_intent' => ['mode' => 'PLAN'],
        ]));
        self::assertSame(CapturePurpose::MIXED, CapturePurposePolicy::resolve([
            'purpose' => 'MIXED',
            'authority_intent' => ['mode' => 'PLAN'],
            'text' => 'Hermle có nội dung editorial.',
        ]));
    }

    public function test_invalid_purpose_is_fail_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AUTHORITY_PURPOSE_CONFLICT');

        CapturePurposePolicy::resolve(['purpose' => 'CLOCK_TYPE', 'text' => 'Không hợp lệ.']);
    }

    public function test_authority_capture_plans_without_creating_a_wordpress_post(): void
    {
        $captures = new AuthorityCaptureRepository();
        $plannerCalls = 0;
        $service = new AuthorityCaptureService(
            $captures,
            static function (array $input, CaptureRecord $capture) use (&$plannerCalls): array {
                ++$plannerCalls;
                return ['reuse' => [], 'create_candidates' => [['candidate_id' => 'candidate-brand-hermle']], 'plan_fingerprint' => str_repeat('a', 64)];
            },
        );

        $input = ['idempotency_key' => 'authority-hermle', 'purpose' => 'AUTHORITY', 'text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'PLAN']];
        $first = $service->execute($input);
        $replay = $service->execute($input);

        self::assertSame(CapturePurpose::AUTHORITY->value, $first->context['purpose']);
        self::assertSame('AUTHORITY_PLANNED', $first->stage);
        self::assertSame('PLANNED', $first->status);
        self::assertNull($first->articleId);
        self::assertSame($first->captureId, $replay->captureId);
        self::assertSame(1, $plannerCalls);
    }

    public function test_mixed_capture_creates_at_most_one_editorial_post_on_replay(): void
    {
        $captures = new AuthorityCaptureRepository();
        $posts = 0;
        $service = new AuthorityCaptureService(
            $captures,
            static fn (array $input, CaptureRecord $capture): array => ['reuse' => [], 'create_candidates' => [], 'plan_fingerprint' => str_repeat('b', 64)],
            static function (array $input, CaptureRecord $capture) use (&$posts): array {
                ++$posts;
                return ['post_id' => 501, 'state_token' => 'editorial-state'];
            },
        );

        $input = ['idempotency_key' => 'mixed-hermle', 'purpose' => 'MIXED', 'text' => 'Hermle có nội dung editorial.', 'authority_intent' => ['mode' => 'PLAN']];
        $first = $service->execute($input);
        $replay = $service->execute($input);

        self::assertSame(CapturePurpose::MIXED->value, $first->context['purpose']);
        self::assertSame(501, $first->articleId);
        self::assertSame($first->captureId, $replay->captureId);
        self::assertSame(1, $posts);
    }

    public function test_same_approval_is_replanned_when_pending_policy_contract_changes(): void
    {
        $captures = new AuthorityCaptureRepository();
        $calls = 0;
        $service = new AuthorityCaptureService(
            $captures,
            static function (array $input, CaptureRecord $capture) use (&$calls): array {
                ++$calls;
                return ['reuse' => [], 'create_candidates' => [['candidate_id' => 'candidate-hermle']], 'plan_fingerprint' => $calls === 1 ? str_repeat('a', 64) : str_repeat('b', 64)];
            },
            null,
            static function (): array { return ['status' => 'APPLIED']; },
        );
        $first = $service->execute(['idempotency_key' => 'pending-policy-change', 'purpose' => 'AUTHORITY', 'text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'PLAN']]);

        $this->expectExceptionMessage('PLAN_REAPPROVAL_REQUIRED');
        $service->continueWithApproval($first->captureId, ['authority_intent' => ['mode' => 'APPLY_APPROVED_PLAN', 'approved_plan_fingerprint' => str_repeat('a', 64), 'approved_candidate_ids' => ['candidate-hermle']]]);
    }

    public function test_mixed_approval_invokes_same_capture_reconciliation_after_apply(): void
    {
        $captures = new AuthorityCaptureRepository();
        $reconciled = [];
        $service = new AuthorityCaptureService(
            $captures,
            static fn (array $input, CaptureRecord $capture): array => ['reuse' => [], 'create_candidates' => [['candidate_id' => 'candidate-hermle']], 'plan_fingerprint' => str_repeat('c', 64)],
            static fn (array $input, CaptureRecord $capture): array => ['post_id' => 901, 'state_token' => 'draft-token'],
            static fn (CaptureRecord $capture, array $plan, array $ids): array => ['status' => 'APPLIED', 'canonical_readback' => ['brand' => 'verified']],
            static function (CaptureRecord $capture, array $result) use (&$reconciled): array {
                $reconciled[] = [$capture->captureId, $result['status']];
                $continued = new CaptureRecord($capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, 'COMPOSED', 'PARTIAL', $capture->articleId, $capture->articleStateToken, $capture->assets, $capture->context + ['editorial_reconciled' => true], $capture->diagnostics, $capture->phaseReceipts, $capture->revision + 3, $capture->createdAt, $capture->updatedAt);
                return ['status' => 'RECONCILED_ON_SAME_CAPTURE', 'post_id' => $capture->articleId, 'capture_record' => $continued];
            },
        );
        $first = $service->execute(['idempotency_key' => 'mixed-reconciliation', 'purpose' => 'MIXED', 'text' => 'Hermle có nội dung editorial.', 'authority_intent' => ['mode' => 'PLAN']]);
        $done = $service->continueWithApproval($first->captureId, ['authority_intent' => ['mode' => 'APPLY_APPROVED_PLAN', 'approved_plan_fingerprint' => str_repeat('c', 64), 'approved_candidate_ids' => ['candidate-hermle']]]);

        self::assertSame('RECONCILED_ON_SAME_CAPTURE', $done->context['mixed_editorial_reconciliation']['status']);
        self::assertSame([[$first->captureId, 'APPLIED']], $reconciled);
        self::assertTrue($done->context['editorial_reconciled']);
        self::assertSame(6, $done->revision);
        self::assertSame(901, $done->articleId);
    }
}

final class AuthorityCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }

    public function findById(string $captureId): ?CaptureRecord
    {
        foreach ($this->records as $record) if ($record->captureId === $captureId) return $record;
        return null;
    }

    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }

    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}
