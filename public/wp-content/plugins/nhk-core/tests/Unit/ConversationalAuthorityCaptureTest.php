<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CapturePurposePolicy;
use NHK\Core\Application\Capture\{AuthorityCaptureService, PlanReapprovalRequired};
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

    public function test_authority_rename_candidate_survives_capture_persistence_and_reload(): void
    {
        $captures = new AuthorityCaptureRepository();
        $candidate = [
            'candidate_id' => 'candidate-rename', 'action' => 'RENAME', 'operation' => 'rename',
            'entity_type' => 'classification', 'canonical_uuid' => '54fae8ec-e7fd-4130-a9da-7469bcaacd29',
            'expected_revision' => 1, 'requested_delta' => ['name' => 'Mặt bát giác nằm'],
            'capture_id' => 'capture-bound', 'plan_fingerprint' => str_repeat('a', 64),
        ];
        $service = new AuthorityCaptureService($captures, static fn (array $input, CaptureRecord $capture): array => [
            'reuse' => [], 'create_candidates' => [], 'update_candidates' => [$candidate],
            'relation_candidates' => [], 'plan_fingerprint' => str_repeat('a', 64),
        ]);

        $planned = $service->execute([
            'idempotency_key' => 'rename-capture', 'purpose' => 'AUTHORITY',
            'authority_intent' => ['mode' => 'PLAN'],
        ]);
        $reloaded = $captures->findById($planned->captureId);

        self::assertNotNull($reloaded);
        self::assertSame($candidate, $reloaded->context['authority_plan']['update_candidates'][0]);
        self::assertSame($planned->captureId, $reloaded->captureId);
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

    public function test_relationship_only_capture_never_invokes_editorial_owner_for_any_admission_purpose(): void
    {
        foreach ([null, 'AUTHORITY', 'MIXED'] as $ordinal => $purpose) {
            $captures = new AuthorityCaptureRepository();
            $editorialCalls = 0;
            $mixedContinuationCalls = 0;
            $service = new AuthorityCaptureService(
                $captures,
                static fn (array $input, CaptureRecord $capture): array => [
                    'relation_candidates' => [[
                        'candidate_id' => 'relationship-add',
                        'entity_type' => 'relation',
                        'action' => 'CREATE',
                        'source_type' => 'model',
                        'source_uuid' => 'source-' . $ordinal,
                        'predicate' => 'model_of',
                        'target_type' => 'brand',
                        'target_uuid' => 'target-' . $ordinal,
                    ]],
                    'relation_reuse' => [],
                    'plan_fingerprint' => str_repeat((string) ($ordinal + 1), 64),
                ],
                static function () use (&$editorialCalls): array {
                    ++$editorialCalls;
                    throw new \LogicException('relationship-only Capture must not create an Article');
                },
                static fn (CaptureRecord $capture, array $plan, array $ids): array => [
                    'status' => 'APPLIED',
                    'proposal_ids' => ['proposal-' . $ordinal],
                    'apply_results' => [['canonical_id' => 'edge-' . $ordinal, 'canonical_readback' => ['canonical_id' => 'edge-' . $ordinal, 'revision' => 1]]],
                    'canonical_readback' => ['canonical_id' => 'edge-' . $ordinal, 'revision' => 1],
                ],
                static function () use (&$mixedContinuationCalls): array {
                    ++$mixedContinuationCalls;
                    throw new \LogicException('relationship-only Capture must not continue editorial reconciliation');
                },
            );
            $input = [
                'idempotency_key' => 'relationship-only-purpose-' . $ordinal,
                'relationship_operations' => [['operation' => 'ADD']],
            ];
            if ($purpose !== null) $input['purpose'] = $purpose;

            $planned = $service->execute($input);
            $applied = $service->continueWithApproval($planned->captureId, [
                'authority_intent' => [
                    'mode' => 'APPLY_APPROVED_PLAN',
                    'approved_plan_fingerprint' => $planned->context['plan_fingerprint'],
                    'approved_candidate_ids' => ['relationship-add'],
                ],
            ]);

            self::assertNull($planned->articleId);
            self::assertNull($applied->articleId);
            self::assertSame('APPLIED', $applied->status);
            self::assertSame(0, $editorialCalls);
            self::assertSame(0, $mixedContinuationCalls);
            self::assertSame('COMPLETE', $applied->context['authority_result']['result']['completion']['canonical_state']);
            self::assertTrue($applied->context['authority_result']['result']['completion']['canonical_readback_verified']);
            self::assertTrue($applied->context['authority_result']['result']['completion']['complete']);
        }
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

        try {
            $service->continueWithApproval($first->captureId, ['authority_intent' => ['mode' => 'APPLY_APPROVED_PLAN', 'approved_plan_fingerprint' => str_repeat('a', 64), 'approved_candidate_ids' => ['candidate-hermle']]]);
            self::fail('Expected a fresh reapproval packet.');
        } catch (PlanReapprovalRequired $error) {
            self::assertSame('PLAN_REAPPROVAL_REQUIRED', $error->getMessage());
            self::assertSame($first->captureId, $error->packet['capture_id']);
            self::assertSame(str_repeat('b', 64), $error->packet['plan_fingerprint']);
            self::assertSame(['candidate-hermle'], $error->packet['candidate_ids']);
            self::assertArrayHasKey('candidates', $error->packet);
            self::assertArrayHasKey('dependencies', $error->packet);
            self::assertSame([], $error->packet['blockers']);
        }
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

    public function test_changed_typed_relation_request_with_same_idempotency_key_is_a_capture_conflict(): void
    {
        $captures = new AuthorityCaptureRepository();
        $service = new AuthorityCaptureService($captures, static fn (array $input, CaptureRecord $capture): array => [
            'relation_candidates' => [['candidate_id' => 'relation-' . ($input['authority_intent']['relation_intents'][0]['target_uuid'] ?? '')]],
            'plan_fingerprint' => str_repeat('d', 64),
        ]);
        $base = ['source_type' => 'classification', 'source_uuid' => '01a07cbc-3595-7e63-8c1b-5b308c644125', 'predicate' => 'about', 'target_type' => 'knowledge', 'target_uuid' => '01a08156-c400-7739-a40f-61185cd62fcd'];
        $input = ['idempotency_key' => 'typed-relation-conflict', 'purpose' => 'AUTHORITY', 'text' => '', 'authority_intent' => ['mode' => 'PLAN', 'relation_intents' => [$base]]];
        $first = $service->execute($input);
        $changed = $input;
        $changed['authority_intent']['relation_intents'][0]['target_uuid'] = '01a08156-c400-7739-a40f-61185cd62fce';
        $conflict = $service->execute($changed);

        self::assertSame($first->captureId, $conflict->captureId);
        self::assertSame('IDEMPOTENCY_CONFLICT', $conflict->status);
        self::assertSame('CAPTURE_IDEMPOTENCY_KEY_REUSED', $conflict->diagnostics['failure']['code']);
    }

    public function test_typed_relation_intent_is_preserved_across_approval_replan(): void
    {
        $captures = new AuthorityCaptureRepository();
        $seen = [];
        $intent = ['source_type' => 'classification', 'source_uuid' => '01a07cbc-3595-7e63-8c1b-5b308c644125', 'predicate' => 'about', 'target_type' => 'knowledge', 'target_uuid' => '01a08156-c400-7739-a40f-61185cd62fcd'];
        $service = new AuthorityCaptureService(
            $captures,
            static function (array $input, CaptureRecord $capture) use (&$seen, $intent): array {
                $seen[] = $input['authority_intent']['relation_intents'];
                return ['relation_candidates' => [['candidate_id' => 'typed-relation']], 'plan_fingerprint' => str_repeat('e', 64)];
            },
            null,
            static fn (CaptureRecord $capture, array $plan, array $ids): array => ['status' => 'APPLIED'],
        );
        $input = ['idempotency_key' => 'typed-relation-replan', 'purpose' => 'AUTHORITY', 'text' => 'prose must not alter relation', 'authority_intent' => ['mode' => 'PLAN', 'relation_intents' => [$intent]]];
        $first = $service->execute($input);
        $service->continueWithApproval($first->captureId, ['authority_intent' => ['mode' => 'APPLY_APPROVED_PLAN', 'approved_plan_fingerprint' => str_repeat('e', 64), 'approved_candidate_ids' => ['typed-relation']]]);

        self::assertCount(2, $seen);
        self::assertSame($seen[0], $seen[1]);
        self::assertSame($intent, $seen[1][0]);
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
