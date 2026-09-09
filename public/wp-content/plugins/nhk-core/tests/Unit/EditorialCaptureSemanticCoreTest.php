<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureSemanticCoreTest extends TestCase
{
    public function test_text_interpretation_resolution_and_claim_retrieval_are_deterministic_and_explainable(): void
    {
        $interpreter = new TextInputInterpreter();
        $interpreted = $interpreter->interpret('Chiếc đồng hồ này có mặt số xanh và là một bản sưu tầm.', [], ['Ô Đô 36/10']);
        $resolver = new SubjectResolutionService(static fn (string $hint): array => $hint === 'Ô Đô 36/10' ? [[
            'id' => 'entity-1', 'type' => 'variant', 'name' => 'Ô Đô 36/10', 'revision' => 3,
        ]] : []);
        $resolved = $resolver->resolve($interpreted['primary_subject_hints']);
        $retriever = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => [[
                'target_entity_id' => 'movement-1', 'target_entity_type' => 'movement', 'hop_count' => 1,
                'best_path' => [['source' => 'variant:entity-1', 'predicate' => 'uses_movement', 'target' => 'movement:movement-1']],
            ]]],
            static fn (array $subject, array $neighborhood): array => [[
                'id' => 'claim-1', 'revision' => 4, 'text' => 'Mặt số xanh là một đặc điểm nhận diện của cấu hình này.',
                'subject_id' => 'movement-1', 'scope' => 'variant', 'provenance' => 'CATALOG_SUPPORTED',
                'evidence_status' => 'SUPPORTED_WITHIN_SCOPE', 'relevance' => 0.9,
            ]],
        );

        $context = [
            'raw_input' => 'Chiếc đồng hồ này có mặt số xanh và là một bản sưu tầm.',
            'assets' => [], 'interpretation' => $interpreted, 'subject_resolution' => $resolved,
        ];
        $retrieved = $retriever->retrieve($context);

        self::assertSame('resolved', $resolved['status']);
        self::assertSame('claim-1', $retrieved['selected_claims'][0]['claim_id']);
        self::assertSame('CATALOG_SUPPORTED', $retrieved['selected_claims'][0]['provenance']);
        self::assertNotEmpty($retrieved['selected_claims'][0]['relation_path']);
        self::assertSame($retrieved, $retriever->retrieve($context));
    }

    public function test_composer_keeps_claim_trace_without_dumping_raw_claims(): void
    {
        $result = (new ArticleComposer())->compose(
            'Một ghi chú ngắn về chiếc đồng hồ.',
            [['media_id' => 'media-1', 'observation' => 'Góc chụp cho thấy mặt số xanh.']],
            [['claim_id' => 'claim-1', 'revision' => 2, 'text' => 'Cấu hình này dùng bộ máy được ghi nhận trong hồ sơ.', 'relation_path' => [], 'provenance' => 'CATALOG_SUPPORTED']],
        );

        self::assertStringContainsString('Một ghi chú ngắn', $result['content']);
        self::assertStringNotContainsString('Cấu hình này dùng bộ máy được ghi nhận trong hồ sơ.', $result['content']);
        self::assertSame('claim-1', $result['claim_trace'][0]['claim_id']);
        self::assertSame(2, $result['claim_trace'][0]['claim_revision']);
    }

    public function test_capture_creates_one_draft_for_multiple_assets_and_retries_without_duplicates(): void
    {
        $repository = new InMemoryCaptureRepository();
        $events = [];
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static function (array $input) use (&$events): array { $events[] = 'physical'; return ['items' => [
                ['client_file_id' => 'a', 'attachment_id' => 11, 'media_id' => 'media-a', 'attachment_readback_status' => 'verified'],
                ['client_file_id' => 'b', 'attachment_id' => 12, 'media_id' => 'media-b', 'attachment_readback_status' => 'verified'],
            ]]; },
            static function (array $input) use (&$events): array { $events[] = 'draft'; return ['post_id' => 55, 'state_token' => 'token-55', 'post' => ['post_id' => 55]]; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$events): array { $events[] = 'semantic'; return ['status' => 'PLANNED', 'writes' => []]; },
            new ArticleComposer(),
            static function (array $context) use (&$events): array { $events[] = 'usage'; return ['status' => 'RECONCILED']; },
            static function (array $context): array { return ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']]; },
            static function (array $context): array { return ['status' => 'verified']; },
        );
        $input = ['idempotency_key' => 'capture-1', 'text' => 'Một ghi chú về hai góc chụp.', 'subject_hints' => [], 'files' => ['a', 'b']];

        $first = $coordinator->execute($input);
        $replay = $coordinator->execute($input);

        self::assertSame(55, $first->articleId, json_encode($first->toArray(), JSON_UNESCAPED_UNICODE));
        self::assertCount(2, $first->assets);
        self::assertSame($first->captureId, $replay->captureId);
        self::assertSame(1, substr_count(implode(',', $events), 'physical'));
        self::assertSame(1, substr_count(implode(',', $events), 'draft'));
        self::assertSame('READY_FOR_PUBLICATION', $first->stage);
        self::assertContains('OWNER_PUBLICATION_REQUIRED', $first->diagnostics['publication']['blockers']);
    }
}

final class InMemoryCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    public array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}
