<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\WordPress\EditorialDraftGateway;
use NHK\Core\Contracts\Article\ArticleOperationReceiptRepository;
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState};
use NHK\Core\Domain\Article\EditorialStateToken;
use NHK\Core\Application\Capture\CaptureEditorialWriteGuard;
use PHPUnit\Framework\TestCase;
use NHK\Core\Application\Semantic\ManagedArticleSectionParser;

final class EditorialDraftGatewayTest extends TestCase
{
    public function test_create_is_draft_only_idempotent_and_receipt_has_no_body(): void
    {
        $posts = new FakeEditorialStore(); $receipts = new FakeReceiptRepo(); $gateway = new EditorialDraftGateway($posts, $receipts);
        $input = ['idempotency_key' => 'draft-1', 'title' => 'Tiêu đề', 'content' => 'Nội dung bí mật', 'research' => ['ready_for_draft' => true, 'runtime' => 'IMPLEMENTATION_READY_RUNTIME_UNVERIFIED']];
        $first = $gateway->create($input); $second = $gateway->create($input);
        self::assertSame(1, $posts->creates); self::assertSame($first['post_id'], $second['post_id']); self::assertSame('draft', $first['post']['status']); self::assertArrayNotHasKey('content', $first['receipt']); self::assertStringNotContainsString('Nội dung bí mật', json_encode($first['receipt'], JSON_UNESCAPED_UNICODE)); self::assertContains('DRAFT_INCOMPLETE_FOR_PUBLICATION', $first['publication_blockers']);
    }

    public function test_stale_state_blocks_update_and_blocked_research_blocks_create(): void
    {
        $posts = new FakeEditorialStore(); $gateway = new EditorialDraftGateway($posts, new FakeReceiptRepo()); $created = $gateway->create(['idempotency_key' => 'draft-2', 'title' => 'A', 'content' => 'B']);
        self::assertSame('EDITORIAL_STATE_CONFLICT', $gateway->update($created['post_id'], ['post_title' => 'C'], str_repeat('0', 64))['reason']);
        self::assertSame('RESEARCH_PREFLIGHT_BLOCKED', $gateway->create(['idempotency_key' => 'draft-3', 'research' => ['ready_for_draft' => false]])['reason']);
    }

    public function test_state_token_match_is_exact_and_uses_the_current_snapshot_as_the_comparison_side(): void
    {
        $state = new EditorialPostState(1, '1:1', 'post', 'draft', 'A', 'B', 'C', 'a', '/?p=1', 7, 3, '2026-09-16 00:00:00');

        self::assertTrue(EditorialStateToken::matches($state->token, $state));
        self::assertFalse(EditorialStateToken::matches(str_repeat('0', 64), $state));
        self::assertFalse(EditorialStateToken::matches(substr($state->token, 0, 63), $state));
    }

    public function test_draft_update_cas_has_no_hidden_mutation_on_three_stale_retries_and_fresh_retry_succeeds_once(): void
    {
        $posts = new ObservingEditorialStore();
        $gateway = new EditorialDraftGateway($posts, new FakeReceiptRepo());
        $created = $gateway->create([
            'idempotency_key' => 'cas-atomic-1',
            'title' => 'Public Clock',
            'content' => 'Bản nháp ban đầu.',
            'excerpt' => 'Tóm tắt ban đầu.',
        ]);
        $t1 = (string) $created['state_token'];

        $first = $gateway->update(1, [
            'post_title' => 'Public Clock — cập nhật',
            'post_content' => 'Bản nháp đã cập nhật.',
            'post_excerpt' => 'Tóm tắt đã cập nhật.',
        ], $t1);
        self::assertTrue($first['ok']);
        $t2 = (string) $first['state_token'];
        self::assertNotSame($t1, $t2);
        $stable = $posts->read(1);
        self::assertNotNull($stable);
        $stableSnapshot = $stable->snapshot();
        $stableWrites = $posts->updates;

        foreach (range(1, 3) as $retry) {
            $conflict = $gateway->update(1, ['post_title' => 'Không được ghi ' . $retry], $t1);
            self::assertFalse($conflict['ok']);
            self::assertSame('EDITORIAL_STATE_CONFLICT', $conflict['reason']);
            self::assertSame($stableSnapshot, $posts->read(1)?->snapshot());
            self::assertSame($stableWrites, $posts->updates);
        }

        $fresh = $gateway->update(1, [
            'post_title' => 'Public Clock — retry hợp lệ',
            'post_content' => 'Bản nháp retry hợp lệ.',
            'post_excerpt' => 'Tóm tắt retry hợp lệ.',
        ], $t2);
        self::assertTrue($fresh['ok']);
        self::assertSame(2, $posts->updates);
        self::assertSame('Public Clock — retry hợp lệ', $fresh['post']['title']);
        self::assertSame('Bản nháp retry hợp lệ.', $fresh['post']['content']);
        self::assertSame('Tóm tắt retry hợp lệ.', $fresh['post']['excerpt']);
    }

    public function test_empty_or_noop_update_does_not_call_native_writer(): void
    {
        $posts = new ObservingEditorialStore();
        $gateway = new EditorialDraftGateway($posts, new FakeReceiptRepo());
        $created = $gateway->create([
            'idempotency_key' => 'cas-noop-1',
            'title' => 'Public Clock',
            'content' => 'Bản nháp.',
            'excerpt' => 'Tóm tắt.',
        ]);

        $result = $gateway->update(1, [
            'post_title' => 'Public Clock',
            'post_content' => 'Bản nháp.',
            'post_excerpt' => 'Tóm tắt.',
        ], (string) $created['state_token']);

        self::assertTrue($result['ok']);
        self::assertSame(0, $posts->updates);
        self::assertSame((string) $created['state_token'], $result['state_token']);
    }

    public function test_capture_draft_write_never_allows_unscoped_historical_media_into_any_editorial_write(): void
    {
        // This is intentionally a pre-fix regression: the current native
        // post-insert hook runs while createDraft is executing without a
        // locked Capture context and can materialize a sibling Media.
        if (!class_exists(CaptureEditorialWriteGuard::class)) {
            self::fail('Capture editorial write guard is not implemented yet.');
        }

        $writes = [];
        $posts = new FakeEditorialStore(static function (array $fields) use (&$writes): void {
            $writes[] = [
                'content' => (string) ($fields['post_content'] ?? ''),
                'featured_media' => CaptureEditorialWriteGuard::active() ? null : 'sibling-attachment',
            ];
        });
        $gateway = new EditorialDraftGateway($posts, new FakeReceiptRepo());

        $created = $gateway->create([
            'idempotency_key' => 'capture-draft-write-1',
            'capture_id' => 'capture-a',
            'title' => 'Variant A',
            'content' => 'Nội dung của Variant A.',
            'research' => ['ready_for_draft' => true],
        ]);
        try {
            $gateway->update($created['post_id'], ['post_content' => 'Nội dung cập nhật.'], (string) $created['state_token'], 'capture-a');
        } catch (\ArgumentCountError $error) {
            self::fail('Capture-owned Article update is not guarded: ' . $error->getMessage());
        }

        self::assertNotEmpty($writes);
        foreach ($writes as $write) {
            self::assertNotSame('sibling-attachment', $write['featured_media']);
            self::assertStringNotContainsString('sibling-attachment', $write['content']);
        }
    }

    public function test_managed_section_expectation_blocks_a_human_edit_before_native_update(): void
    {
        $parser = new ManagedArticleSectionParser();
        $section = ['section_id' => 'nhk-managed-section-1', 'fingerprint' => hash('sha256', 'Canonical copy'), 'dependency_fingerprint' => hash('sha256', 'deps'), 'content' => 'Canonical copy'];
        $posts = new FakeEditorialStore();
        $gateway = new EditorialDraftGateway($posts, new FakeReceiptRepo());
        $created = $gateway->create(['idempotency_key' => 'managed-1', 'content' => $parser->wrap($section['section_id'], $section['fingerprint'], $section['dependency_fingerprint'], $section['content'])]);
        $posts->rows[1] = new EditorialPostState(1, '1:1', 'post', 'draft', '', str_replace('Canonical copy', 'Human edit', $posts->rows[1]->content), '', '', '/?p=1', 0, 1);

        $result = $gateway->update(1, ['post_content' => 'replacement', 'managed_section_expectations' => [$section]], $posts->rows[1]->token);
        self::assertFalse($result['ok']);
        self::assertSame('EDITORIAL_CONFLICT', $result['reason']);
    }
}

final class FakeEditorialStore implements EditorialPostStore
{
    /** @var array<int,EditorialPostState> */ public array $rows = []; public int $creates = 0;
    public function __construct(private $onCreate = null) {}
    public function read(int $postId): ?EditorialPostState { return $this->rows[$postId] ?? null; }
    public function createDraft(array $fields): EditorialPostState { $this->creates++; if (is_callable($this->onCreate)) ($this->onCreate)($fields); return $this->rows[1] = new EditorialPostState(1, '1:1', 'post', 'draft', (string) ($fields['post_title'] ?? ''), (string) ($fields['post_content'] ?? ''), '', '', '/?p=1', 0, 0); }
    public function update(int $postId, array $fields): EditorialPostState { if (is_callable($this->onCreate)) ($this->onCreate)($fields); $old = $this->rows[$postId]; return $this->rows[$postId] = new EditorialPostState($postId, $old->endpointKey, $old->postType, 'draft', (string) ($fields['post_title'] ?? $old->title), (string) ($fields['post_content'] ?? $old->content), $old->excerpt, $old->slug, $old->permalink, $old->latestRevisionId, $old->revisionCount + 1); }
    public function publish(int $postId): EditorialPostState { return $this->rows[$postId] = $this->withStatus($this->rows[$postId], 'publish'); }
    public function trash(int $postId): EditorialPostState { return $this->rows[$postId] = $this->withStatus($this->rows[$postId], 'trash'); }
    public function restore(int $postId): EditorialPostState { return $this->rows[$postId] = $this->withStatus($this->rows[$postId], 'draft'); }
    private function withStatus(EditorialPostState $old, string $status): EditorialPostState { return new EditorialPostState($old->postId, $old->endpointKey, $old->postType, $status, $old->title, $old->content, $old->excerpt, $old->slug ?: 'title', $old->permalink, $old->latestRevisionId + 1, $old->revisionCount + 1); }
}

final class ObservingEditorialStore implements EditorialPostStore
{
    /** @var array<int,EditorialPostState> */
    public array $rows = [];
    public int $updates = 0;

    public function read(int $postId): ?EditorialPostState { return $this->rows[$postId] ?? null; }
    public function createDraft(array $fields): EditorialPostState
    {
        return $this->rows[1] = new EditorialPostState(1, '1:1', 'post', 'draft', (string) ($fields['post_title'] ?? ''), (string) ($fields['post_content'] ?? ''), (string) ($fields['post_excerpt'] ?? ''), '', '/?p=1', 0, 0, '2026-09-16 00:00:00');
    }
    public function update(int $postId, array $fields): EditorialPostState
    {
        $this->updates++;
        $old = $this->rows[$postId];
        return $this->rows[$postId] = new EditorialPostState(
            $postId,
            $old->endpointKey,
            $old->postType,
            'draft',
            (string) ($fields['post_title'] ?? $old->title),
            (string) ($fields['post_content'] ?? $old->content),
            (string) ($fields['post_excerpt'] ?? $old->excerpt),
            $old->slug,
            $old->permalink,
            $old->latestRevisionId + 1,
            $old->revisionCount + 1,
            '2026-09-16 00:00:01',
        );
    }
    public function publish(int $postId): EditorialPostState { return $this->rows[$postId]; }
    public function trash(int $postId): EditorialPostState { return $this->rows[$postId]; }
    public function restore(int $postId): EditorialPostState { return $this->rows[$postId]; }
}

final class FakeReceiptRepo implements ArticleOperationReceiptRepository
{
    /** @var array<string,ArticleOperationReceipt> */ public array $rows = [];
    public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->rows[$key] ?? null; }
    public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] ??= $receipt; }
    public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] = $receipt; }
}
