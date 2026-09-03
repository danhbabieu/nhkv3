<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\WordPress\EditorialDraftGateway;
use NHK\Core\Contracts\Article\ArticleOperationReceiptRepository;
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState};
use PHPUnit\Framework\TestCase;

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
}

final class FakeEditorialStore implements EditorialPostStore
{
    /** @var array<int,EditorialPostState> */ public array $rows = []; public int $creates = 0;
    public function read(int $postId): ?EditorialPostState { return $this->rows[$postId] ?? null; }
    public function createDraft(array $fields): EditorialPostState { $this->creates++; return $this->rows[1] = new EditorialPostState(1, '1:1', 'post', 'draft', (string) ($fields['post_title'] ?? ''), (string) ($fields['post_content'] ?? ''), '', '', '/?p=1', 0, 0); }
    public function update(int $postId, array $fields): EditorialPostState { $old = $this->rows[$postId]; return $this->rows[$postId] = new EditorialPostState($postId, $old->endpointKey, $old->postType, 'draft', (string) ($fields['post_title'] ?? $old->title), $old->content, $old->excerpt, $old->slug, $old->permalink, $old->latestRevisionId, $old->revisionCount + 1); }
    public function publish(int $postId): EditorialPostState { return $this->rows[$postId] = $this->withStatus($this->rows[$postId], 'publish'); }
    public function trash(int $postId): EditorialPostState { return $this->rows[$postId] = $this->withStatus($this->rows[$postId], 'trash'); }
    public function restore(int $postId): EditorialPostState { return $this->rows[$postId] = $this->withStatus($this->rows[$postId], 'draft'); }
    private function withStatus(EditorialPostState $old, string $status): EditorialPostState { return new EditorialPostState($old->postId, $old->endpointKey, $old->postType, $status, $old->title, $old->content, $old->excerpt, $old->slug ?: 'title', $old->permalink, $old->latestRevisionId + 1, $old->revisionCount + 1); }
}

final class FakeReceiptRepo implements ArticleOperationReceiptRepository
{
    /** @var array<string,ArticleOperationReceipt> */ public array $rows = [];
    public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->rows[$key] ?? null; }
    public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] ??= $receipt; }
    public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] = $receipt; }
}
