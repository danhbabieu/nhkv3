<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{EditorialCaptureContinuationService, EditorialCaptureCoordinator};
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\{CaptureAddendumRepository, CaptureRepository};
use NHK\Core\Domain\Capture\{CaptureAddendumRecord, CaptureRecord, CaptureStage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureContinuationTest extends TestCase
{
    public function test_addendum_reuses_same_capture_and_article_without_replaying_physical_phase(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'capture-original',
            hash('sha256', 'original'),
            CaptureStage::READY_FOR_PUBLICATION->value,
            'PARTIAL',
            342,
            'state-342',
            [],
            ['raw_input' => 'Ghi chú ban đầu.', 'subject_hints' => ['Odo 30']],
            ['composition' => ['title' => 'Bài 342']],
            [],
        );
        $captures->create($capture);
        $events = [];
        $coordinator = $this->coordinator($captures, $events);
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator);
        $input = ['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-1', 'text' => 'Bổ sung tri thức thực địa.', 'subject_hints' => ['Odo 30']];

        $first = $service->execute($input);
        $replay = $service->execute($input);

        self::assertSame('COMPLETED', $first['addendum']['status']);
        self::assertSame($capture->captureId, $first['capture']['capture_id']);
        self::assertSame(342, $first['capture']['article_id']);
        self::assertSame($first['addendum']['addendum_id'], $replay['addendum']['addendum_id']);
        self::assertSame(1, $events['semantic']);
        self::assertSame(1, $events['media']);
        self::assertArrayNotHasKey('physical', $events);
        self::assertArrayNotHasKey('draft', $events);
        self::assertStringContainsString('Ghi chú ban đầu.', $events['merged_text']);
        self::assertStringContainsString('Bổ sung tri thức thực địa.', $events['merged_text']);
    }

    public function test_same_addendum_key_with_changed_payload_conflicts_without_rerunning_capture(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));
        $base = ['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-conflict', 'text' => 'Bổ sung A.'];

        $service->execute($base);
        $conflict = $service->execute(array_merge($base, ['text' => 'Bổ sung B.']));

        self::assertSame('IDEMPOTENCY_CONFLICT', $conflict['addendum']['status']);
        self::assertSame('CAPTURE_ADDENDUM_IDEMPOTENCY_CONFLICT', $conflict['addendum']['diagnostics']['code']);
        self::assertSame(1, $events['semantic']);
    }

    public function test_second_addendum_resumes_from_the_previous_addendum_checkpoint(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-a', 'text' => 'Bổ sung A.']);
        $second = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-b', 'text' => 'Bổ sung B.']);

        self::assertSame('COMPLETED', $second['addendum']['status']);
        self::assertSame(2, $events['semantic']);
        self::assertStringContainsString('Bổ sung A.', $events['merged_text']);
        self::assertStringContainsString('Bổ sung B.', $events['merged_text']);
        self::assertCount(2, $second['capture']['context']['continuations']);
        self::assertStringContainsString('Bổ sung A.', $second['capture']['context']['continuation_state']['raw_input']);
    }

    public function test_governance_apply_readback_is_consumed_by_capture_continuation_without_publication(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $applied = false;
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static function (): array { throw new \RuntimeException('physical phase must not replay'); },
            static function (): array { throw new \RuntimeException('draft phase must not replay'); },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$applied, &$events): array {
                $events['governance_lifecycle'] = ['PROPOSAL', 'SUBMIT', 'APPROVE', 'ELIGIBILITY', 'CONTROLLED_APPLY', 'CANONICAL_READ_BACK'];
                return $applied
                    ? ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => 'canonical-claim-36-8', 'revision' => 2, 'subject_id' => 'variant-36-8'], 'writes' => []]
                    : ['status' => 'REVIEW_REQUIRED', 'writes' => [['kind' => 'claim_candidate', 'scope' => 'capture', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]];
            },
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['SEMANTIC_REVIEW_REQUIRED', 'MEDIAUSAGE_INCOMPLETE']],
            static fn (array $context): array => ['status' => 'verified'],
        );
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator);

        $first = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'governance-pending', 'text' => 'Côn chữ U màu trắng.', 'subject_hints' => ['variant-36-8', 'Côn chữ U']]);
        // The normal operator lifecycle is Proposal → Submit → Approve →
        // Eligibility → Controlled Apply → canonical read-back. This test
        // supplies that verified result before resuming the same Capture.
        $applied = true;
        $second = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'governance-applied', 'text' => '']);

        self::assertSame('REVIEW_REQUIRED', $first['capture']['diagnostics']['semantic_write_back']['status']);
        self::assertSame('APPLIED', $second['capture']['diagnostics']['semantic_write_back']['status']);
        self::assertSame($capture->articleId, $second['capture']['article_id']);
        self::assertSame(['PROPOSAL', 'SUBMIT', 'APPROVE', 'ELIGIBILITY', 'CONTROLLED_APPLY', 'CANONICAL_READ_BACK'], $events['governance_lifecycle']);
        self::assertContains('SEMANTIC_REVIEW_REQUIRED', $second['capture']['diagnostics']['publication']['blockers']);
        self::assertSame('verified', $second['capture']['diagnostics']['final_read_back']['status']);
    }

    public function test_addendum_rejects_files_and_keeps_original_capture_request_immutable(): void
    {
        $captures = new ContinuationCaptureRepository();
        $addenda = new ContinuationAddendumRepository();
        $capture = $this->capture();
        $captures->create($capture);
        $events = [];
        $service = new EditorialCaptureContinuationService($captures, $addenda, $this->coordinator($captures, $events));

        $result = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'addendum-files', 'text' => 'Không upload lại.', 'files' => ['unexpected']]);

        self::assertSame('FAILED', $result['addendum']['status']);
        self::assertSame('CAPTURE_ADDENDUM_FILES_NOT_ALLOWED', $result['addendum']['diagnostics']['code']);
        self::assertSame($capture->requestFingerprint, $captures->findById($capture->captureId)?->requestFingerprint);
    }

    private function capture(): CaptureRecord
    {
        return new CaptureRecord(UuidCodec::newV7(), 'capture-original-' . bin2hex(random_bytes(2)), hash('sha256', 'original'), CaptureStage::READY_FOR_PUBLICATION->value, 'PARTIAL', 342, 'state-342', [], ['raw_input' => 'Ghi chú ban đầu.', 'subject_hints' => ['Odo 30']], ['composition' => ['title' => 'Bài 342']], []);
    }

    /** @param array<string,int|string> $events */
    private function coordinator(ContinuationCaptureRepository $captures, array &$events): EditorialCaptureCoordinator
    {
        return new EditorialCaptureCoordinator(
            $captures,
            static function () use (&$events): array { $events['physical'] = ($events['physical'] ?? 0) + 1; throw new \RuntimeException('physical phase must not replay'); },
            static function () use (&$events): array { $events['draft'] = ($events['draft'] ?? 0) + 1; throw new \RuntimeException('draft phase must not replay'); },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$events): array { $events['semantic'] = ($events['semantic'] ?? 0) + 1; $events['merged_text'] = $context['raw_input']; return ['status' => 'REVIEW_REQUIRED', 'writes' => []]; },
            new ArticleComposer(),
            static function (array $context) use (&$events): array { $events['media'] = ($events['media'] ?? 0) + 1; return ['status' => 'RECONCILED']; },
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            static function (array $context): array { return ['ok' => true, 'state_token' => $context['expected_state_token'] ?? 'state-342']; },
        );
    }
}

final class ContinuationCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    public array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { foreach ($this->records as $record) if ($record->idempotencyKey === $key) return $record; return null; }
    public function findById(string $captureId): ?CaptureRecord { return $this->records[$captureId] ?? null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
}

final class ContinuationAddendumRepository implements CaptureAddendumRepository
{
    /** @var array<string,CaptureAddendumRecord> */
    public array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureAddendumRecord { return $this->records[$key] ?? null; }
    public function create(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
    public function save(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
}
