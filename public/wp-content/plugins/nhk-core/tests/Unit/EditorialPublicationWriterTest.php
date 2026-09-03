<?php
declare(strict_types=1);
namespace NHKTests\Unit;

use NHK\Core\Application\WordPress\EditorialDraftGateway;
use PHPUnit\Framework\TestCase;

final class EditorialPublicationWriterTest extends TestCase
{
    public function test_publish_requires_gate_and_is_idempotent(): void
    {
        $posts = new PublicationFakeEditorialStore(); $receipts = new PublicationFakeReceiptRepo(); $gateway = new EditorialDraftGateway($posts, $receipts);
        $created = $gateway->create(['idempotency_key' => 'writer-draft', 'title' => 'T', 'content' => 'B']);
        $blocked = $gateway->publish(1, $created['state_token'], [], 'publish-1');
        self::assertFalse($blocked['ok']); self::assertSame('PUBLICATION_BLOCKED', $blocked['reason']); self::assertSame(1, $posts->creates);
        $evidence = array_fill_keys(['research_acceptable','subject_resolved','duplicate_intent_handled','category_resolved','semantic_plan_complete','semantic_readback_verified','media_usage_complete','real_image_requirements_met','claim_compliance_acceptable','seo_projection_valid','internal_links_valid','structured_data_valid','public_route_ready'], true);
        $first = $gateway->publish(1, $created['state_token'], $evidence, 'publish-2'); $second = $gateway->publish(1, $created['state_token'], $evidence, 'publish-2');
        self::assertTrue($first['ok']); self::assertSame('publish', $first['post']['status']); self::assertSame($first['post_id'] ?? 1, $second['post']['post_id']);
    }

    public function test_trash_and_restore_are_cas_protected_and_idempotent(): void
    {
        $posts = new PublicationFakeEditorialStore(); $gateway = new EditorialDraftGateway($posts, new PublicationFakeReceiptRepo());
        $created = $gateway->create(['idempotency_key' => 'lifecycle', 'title' => 'T', 'content' => 'B']);
        $published = $gateway->publish(1, $created['state_token'], array_fill_keys(['research_acceptable','subject_resolved','duplicate_intent_handled','category_resolved','semantic_plan_complete','semantic_readback_verified','media_usage_complete','real_image_requirements_met','claim_compliance_acceptable','seo_projection_valid','internal_links_valid','structured_data_valid','public_route_ready'], true), 'pub');
        $trashed = $gateway->trash(1, $published['state_token'], 'trash'); self::assertSame('trash', $trashed['post']['status']);
        self::assertSame('EDITORIAL_STATE_CONFLICT', $gateway->restore(1, $created['state_token'], 'restore-bad')['reason']);
        self::assertSame('draft', $gateway->restore(1, $trashed['state_token'], 'restore')['post']['status']);
    }
}

final class PublicationFakeEditorialStore implements \NHK\Core\Contracts\WordPress\EditorialPostStore
{
    public int $creates = 0; /** @var array<int,\NHK\Core\Domain\Article\EditorialPostState> */ public array $rows = [];
    public function read(int $postId): ?\NHK\Core\Domain\Article\EditorialPostState { return $this->rows[$postId] ?? null; }
    public function createDraft(array $fields): \NHK\Core\Domain\Article\EditorialPostState { $this->creates++; return $this->rows[1] = new \NHK\Core\Domain\Article\EditorialPostState(1, '1:1', 'post', 'draft', (string) ($fields['post_title'] ?? ''), (string) ($fields['post_content'] ?? ''), '', 'title', '/title/', 1, 1); }
    public function update(int $postId, array $fields): \NHK\Core\Domain\Article\EditorialPostState { return $this->rows[$postId]; }
    public function publish(int $postId): \NHK\Core\Domain\Article\EditorialPostState { return $this->status('publish'); }
    public function trash(int $postId): \NHK\Core\Domain\Article\EditorialPostState { return $this->status('trash'); }
    public function restore(int $postId): \NHK\Core\Domain\Article\EditorialPostState { return $this->status('draft'); }
    private function status(string $status): \NHK\Core\Domain\Article\EditorialPostState { $old = $this->rows[1]; return $this->rows[1] = new \NHK\Core\Domain\Article\EditorialPostState(1, '1:1', 'post', $status, $old->title, $old->content, $old->excerpt, 'title', '/title/', $old->latestRevisionId + 1, $old->revisionCount + 1); }
}

final class PublicationFakeReceiptRepo implements \NHK\Core\Contracts\Article\ArticleOperationReceiptRepository
{
    /** @var array<string,\NHK\Core\Domain\Article\ArticleOperationReceipt> */ public array $rows = [];
    public function findByIdempotencyKey(string $key): ?\NHK\Core\Domain\Article\ArticleOperationReceipt { return $this->rows[$key] ?? null; }
    public function create(\NHK\Core\Domain\Article\ArticleOperationReceipt $receipt): \NHK\Core\Domain\Article\ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] ??= $receipt; }
    public function save(\NHK\Core\Domain\Article\ArticleOperationReceipt $receipt): \NHK\Core\Domain\Article\ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] = $receipt; }
}
