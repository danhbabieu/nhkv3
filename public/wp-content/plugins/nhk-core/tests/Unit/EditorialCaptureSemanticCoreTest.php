<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Semantic\{ArticleComposer, CanonicalAuthoritySubjectResolver, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, EntityTypeRegistry, CanonicalEntityTypeCatalog};
use NHK\Tests\Support\InMemoryAuthorityRepository;
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

    public function test_text_only_capture_does_not_emit_media_observation_prose(): void
    {
        $result = (new ArticleComposer())->compose(
            'Người dùng ghi nhận cấu hình này thường gặp ở thực địa.',
            [[
                'text' => 'Đây là tri thức người dùng cung cấp.',
                'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
                'media_id' => '',
            ]],
            [],
            ['asset_count' => 0],
        );

        self::assertStringNotContainsString('Quan sát từ tư liệu gửi kèm cho thấy', $result['content']);
        self::assertStringContainsString('Người dùng ghi nhận', $result['content']);
    }

    public function test_capture_subject_resolution_uses_registered_identity_not_unrelated_fallback(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $modelId = 'fdf5bfd5-d3f4-4281-a39e-77c9271bcf4a';
        $repository->create(new AuthorityEntity(
            $modelId,
            'model',
            'nhk:model:odo.30',
            'Odo 30',
            1,
            ['aliases' => ['Máy Odo 30']],
            AuthorityState::ACTIVE,
            1,
        ));

        $resolver = new CanonicalAuthoritySubjectResolver($repository, $types);

        self::assertSame($modelId, $resolver->resolve($modelId)[0]['id']);
        self::assertSame('uuid_exact', $resolver->resolve($modelId)[0]['match']);
        self::assertSame($modelId, $resolver->resolve('nhk:model:odo.30')[0]['id']);
        self::assertSame($modelId, $resolver->resolve('Máy Odo 30')[0]['id']);
        self::assertSame([], $resolver->resolve('Odo 36'));
    }

    public function test_exact_odo_36_10_hint_resolves_the_existing_variant_identity(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $variantId = '95873bfe-d978-4eda-a5a2-ce9ba79625df';
        $repository->create(new AuthorityEntity(
            $variantId,
            'variant',
            'nhk:variant:odo.36.10',
            'Đồng hồ Odo 36/10',
            1,
            ['aliases' => ['Odo 36/10']],
            revision: 2,
        ));

        $resolved = (new CanonicalAuthoritySubjectResolver($repository, $types))->resolve('Odo 36/10');

        self::assertCount(1, $resolved);
        self::assertSame($variantId, $resolved[0]['id']);
        self::assertSame('variant', $resolved[0]['type']);
        self::assertSame('nhk:variant:odo.36.10', $resolved[0]['stable_key']);
        self::assertSame('Đồng hồ Odo 36/10', $resolved[0]['name']);
        self::assertSame(2, $resolved[0]['revision']);
    }

    public function test_capture_resolution_gives_explicit_uuid_precedence_over_prose_hints(): void
    {
        $variantId = '95873bfe-d978-4eda-a5a2-ce9ba79625df';
        $resolution = (new SubjectResolutionService(fn (string $hint): array => match ($hint) {
            $variantId => [['id' => $variantId, 'type' => 'variant', 'name' => 'Đồng hồ Odo 36/10', 'revision' => 2]],
            'Odo 36' => [['id' => 'c01c109c-5d39-401e-a16e-6d61a0a52f50', 'type' => 'model', 'name' => 'Odo 36', 'revision' => 4]],
            default => [],
        }))->resolve([$variantId, 'Odo 36']);

        self::assertSame($variantId, $resolution['primary']['id']);
        self::assertSame([$variantId], array_column($resolution['subjects'], 'id'));
    }

    public function test_user_knowledge_is_atomized_and_keeps_scope_and_attribution_diagnostics(): void
    {
        $interpreted = (new TextInputInterpreter())->interpret(implode("\n", [
            'Odo 36/10 là dòng được nhiều người yêu thích.',
            'Cấu hình 10 côn 10 búa, chơi 2 bài nhạc.',
            'Chiếc đồng hồ trong video được xác nhận là nguyên bản.',
            'Người dùng đánh giá âm thanh tốt.',
            'Cách gọi nữ hoàng âm thanh là nhận xét của cộng đồng.',
        ]), [], ['Odo 36/10']);

        self::assertCount(5, $interpreted['user_claim_candidates']);
        self::assertSame('EXPLICIT_USER_KNOWLEDGE', $interpreted['user_claim_candidates'][0]['provenance']);
        self::assertSame('variant', $interpreted['user_claim_candidates'][1]['scope']);
        self::assertSame('specimen_observation', $interpreted['user_claim_candidates'][2]['scope']);
        self::assertTrue($interpreted['user_claim_candidates'][3]['attributed']);
        self::assertTrue($interpreted['user_claim_candidates'][4]['review_required']);
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

    public function test_video_input_uses_capture_and_preserves_distinct_video_owner_context(): void
    {
        $repository = new InMemoryCaptureRepository();
        $seen = [];
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => ['items' => [['kind' => 'video', 'video_id' => 'video-1', 'video_proposal' => ['entity_type' => 'video']]]],
            static fn (array $input): array => ['post_id' => 58, 'state_token' => 'token-58', 'post' => ['post_id' => 58]],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$seen): array { $seen = $context['assets']; return ['status' => 'REVIEW_REQUIRED', 'writes' => $context['assets']]; },
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
        );

        $result = $coordinator->execute(['idempotency_key' => 'capture-video-1', 'text' => 'Ghi chú về Video.', 'video' => ['url' => 'https://youtu.be/video-1']]);

        self::assertSame('READY_FOR_PUBLICATION', $result->stage);
        self::assertSame(58, $result->articleId);
        self::assertSame('video', $seen[0]['kind']);
        self::assertSame('video-1', $seen[0]['video_id']);
    }

    public function test_video_enrichment_receives_the_capture_resolution_after_subject_lock(): void
    {
        $repository = new InMemoryCaptureRepository();
        $events = [];
        $variant = [
            'id' => '95873bfe-d978-4eda-a5a2-ce9ba79625df',
            'type' => 'variant',
            'stable_key' => 'nhk:variant:odo.36.10',
            'name' => 'Đồng hồ Odo 36/10',
            'revision' => 2,
            'match' => 'exact_name_or_alias',
        ];
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 355, 'state_token' => 'token-355', 'post' => ['post_id' => 355]],
            new TextInputInterpreter(),
            new SubjectResolutionService(fn (string $hint): array => $hint === 'Odo 36/10' ? [$variant] : []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context): array { return ['status' => 'REVIEW_REQUIRED', 'writes' => []]; },
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            static function (array $context) use (&$events): array {
                $events[] = $context;
                return ['items' => [['kind' => 'video', 'video_id' => 'video-odo-36-10', 'video_proposal' => ['entity_type' => 'video']]]];
            },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'capture-odo-36-10-handoff',
            'text' => 'Odo 36/10 có 10 côn 10 búa và chơi 2 bài nhạc.',
            'subject_hints' => ['Odo 36/10'],
            'video' => ['url' => 'https://www.youtube.com/watch?v=oRfvArkX8NA', 'user_hint' => 'Odo 36/10'],
        ]);

        self::assertSame('READY_FOR_PUBLICATION', $result->stage, json_encode($result->toArray(), JSON_UNESCAPED_UNICODE));
        self::assertCount(1, $events);
        self::assertSame($variant, $events[0]['subject_resolution']['primary']);
        self::assertSame($variant['id'], $events[0]['subject_resolution']['primary']['id']);
    }

    public function test_invalid_media_blueprint_is_system_blocked_not_retryable(): void
    {
        $repository = new InMemoryCaptureRepository();
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 355, 'state_token' => 'token-355', 'post' => ['post_id' => 355]],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static function (array $context): array { throw new \InvalidArgumentException('Article Media Blueprint is invalid.'); },
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
        );

        $result = $coordinator->execute(['idempotency_key' => 'capture-invalid-blueprint', 'text' => 'Ghi chú.']);

        self::assertSame('SYSTEM_BLOCKED', $result->status);
        self::assertSame('ARTICLE_MEDIA_BLUEPRINT_IS_INVALID_', $result->diagnostics['failure']['code']);
    }

    public function test_multipart_fingerprint_binds_file_content_without_array_cast_warnings(): void
    {
        $repository = new InMemoryCaptureRepository();
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 56, 'state_token' => 'token-56', 'post' => ['post_id' => 56]],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
        );

        $firstPath = tempnam(sys_get_temp_dir(), 'nhk-capture-');
        $secondPath = tempnam(sys_get_temp_dir(), 'nhk-capture-');
        self::assertIsString($firstPath);
        self::assertIsString($secondPath);
        file_put_contents($firstPath, 'first image');
        file_put_contents($secondPath, 'second image');
        $input = static fn (string $path): array => [
            'idempotency_key' => 'capture-multipart-fingerprint',
            'text' => 'Multipart fingerprint test.',
            'files' => ['files' => [
                'name' => ['capture.jpg'],
                'size' => [filesize($path)],
                'tmp_name' => [$path],
            ]],
        ];

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $first = $coordinator->execute($input($firstPath));
        } finally {
            restore_error_handler();
        }
        $conflict = $coordinator->execute($input($secondPath));
        @unlink($firstPath);
        @unlink($secondPath);

        self::assertSame('READY_FOR_PUBLICATION', $first->stage);
        self::assertSame('IDEMPOTENCY_CONFLICT', $conflict->status);
        self::assertSame('CAPTURE_IDEMPOTENCY_KEY_REUSED', $conflict->diagnostics['failure']['code']);
    }

    public function test_publication_state_token_refresh_converges_without_replaying_stale_plan(): void
    {
        $repository = new InMemoryCaptureRepository();
        $publicationCalls = 0;
        $publisherToken = '';
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 57, 'state_token' => 'token-old', 'post' => ['post_id' => 57]],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static function (array $context) use (&$publicationCalls): array {
                $publicationCalls++;
                return $publicationCalls === 1
                    ? ['eligible' => false, 'blockers' => ['EDITORIAL_CAS_REQUIRED']]
                    : ['eligible' => true, 'blockers' => [], 'state_token' => 'token-current'];
            },
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            static function (array $context) use (&$publisherToken): array {
                $publisherToken = (string) ($context['expected_state_token'] ?? '');
                return ['ok' => true, 'post' => ['status' => 'publish']];
            },
        );

        $result = $coordinator->execute(['idempotency_key' => 'capture-publication-refresh', 'text' => 'Bài đã được xác minh.', 'publish' => true]);

        self::assertSame('PUBLISHED', $result->stage);
        self::assertSame(2, $publicationCalls);
        self::assertSame('token-current', $publisherToken);
    }
}

final class InMemoryCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    public array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}
