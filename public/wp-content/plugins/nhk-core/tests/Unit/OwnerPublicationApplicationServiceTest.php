<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use DateTimeImmutable;
use NHK\Core\Application\Article\OwnerPublicationApplicationService;
use NHK\Core\Contracts\Article\{OwnerPublicationDecisionRepository, PublicationPrincipal};
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticlePublicationOutcome, EditorialPostState, OwnerPublicationDecision};
use PHPUnit\Framework\TestCase;

final class OwnerPublicationApplicationServiceTest extends TestCase
{
    public function test_review_is_read_only_for_a_fully_publishable_draft(): void
    {
        $posts = new OwnerPublicationFakeStore();
        $service = new OwnerPublicationApplicationService($posts, new OwnerPublicationFakeDecisionRepository(), static fn (PublicationPrincipal $principal): bool => $principal->id === 'owner-1');

        $result = $service->review(1, $posts->rows[1]->token, ownerPublicationEvidence(), 'review-only', new PublicationPrincipal('owner-1', 'mcp', 'turn-review'));

        self::assertSame('PASS', $result['outcome']);
        self::assertSame('draft', $posts->rows[1]->status);
        self::assertSame(0, $posts->publishCalls);
    }

    public function test_review_then_authenticated_approval_publishes_with_exceptions(): void
    {
        $posts = new OwnerPublicationFakeStore(); $decisions = new OwnerPublicationFakeDecisionRepository();
        $service = new OwnerPublicationApplicationService($posts, $decisions, static fn (PublicationPrincipal $principal): bool => $principal->id === 'owner-1', static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-03T10:00:00+00:00'));
        $evidence = ownerPublicationEvidence(); $evidence['real_image_requirements_met'] = false; $evidence['real_image_requirements_met_status'] = 'missing';
        $review = $service->request(1, $posts->rows[1]->token, $evidence, 'publish-1', new PublicationPrincipal('owner-1', 'mcp', 'turn-1'));
        self::assertSame('OWNER_REVIEW_REQUIRED', $review['outcome']);
        $result = $service->approveAndPublish(1, $posts->rows[1]->token, $evidence, 'publish-1', $review['decision_id'], new PublicationPrincipal('owner-1', 'mcp', 'turn-2'), 'Đăng.');
        self::assertSame('PASS', $result['outcome']); self::assertSame('published_with_exceptions', $result['final_outcome']); self::assertSame('publish', $result['post']['status']); self::assertContains('REAL_IMAGE_INCOMPLETE', $result['diagnostics']);
    }

    public function test_changed_token_wrong_owner_expiry_and_system_block_are_not_overridable(): void
    {
        $posts = new OwnerPublicationFakeStore(); $decisions = new OwnerPublicationFakeDecisionRepository(); $now = new DateTimeImmutable('2026-09-03T10:00:00+00:00');
        $service = new OwnerPublicationApplicationService($posts, $decisions, static fn (PublicationPrincipal $principal): bool => $principal->id === 'owner-1', function () use (&$now): DateTimeImmutable { return $now; });
        $evidence = ownerPublicationEvidence(); $evidence['seo_projection_valid'] = false;
        $review = $service->request(1, $posts->rows[1]->token, $evidence, 'publish-2', new PublicationPrincipal('owner-1', 'mcp', 'turn-1'));
        self::assertSame('OWNER_REVIEW_REQUIRED', $review['outcome']);
        self::assertSame('OWNER_REVIEW_REQUIRED', $service->approveAndPublish(1, str_repeat('0', 64), $evidence, 'publish-2', $review['decision_id'], new PublicationPrincipal('owner-1', 'mcp', 'turn-2'), 'Đăng.')['outcome']);
        self::assertSame('SYSTEM_BLOCKED', $service->request(1, $posts->rows[1]->token, ownerPublicationEvidence(['public_route_ready' => false]), 'publish-3', new PublicationPrincipal('owner-1', 'mcp', 'turn-3'))['outcome']);
        self::assertSame('SYSTEM_BLOCKED', $service->request(1, $posts->rows[1]->token, ownerPublicationEvidence(), 'publish-4', new PublicationPrincipal('other', 'mcp', 'turn-4'))['outcome']);
    }
}

/** @param array<string,bool> $overrides @return array<string,mixed> */
function ownerPublicationEvidence(array $overrides = []): array
{
    $evidence = array_fill_keys(['research_acceptable','subject_resolved','duplicate_intent_handled','category_resolved','semantic_plan_complete','semantic_readback_verified','media_usage_complete','real_image_requirements_met','claim_compliance_acceptable','seo_projection_valid','internal_links_valid','structured_data_valid','public_route_ready','rendered_public_verification'], true);
    return array_replace($evidence, $overrides);
}

final class OwnerPublicationFakeStore implements EditorialPostStore
{
    /** @var array<int,EditorialPostState> */ public array $rows;
    public int $publishCalls = 0;
    public function __construct() { $this->rows[1] = new EditorialPostState(1, '1:1', 'post', 'draft', 'Title', 'Body', '', 'title', 'https://example.test/title/', 1, 1); }
    public function read(int $postId): ?EditorialPostState { return $this->rows[$postId] ?? null; }
    public function createDraft(array $fields): EditorialPostState { return $this->rows[1]; }
    public function update(int $postId, array $fields): EditorialPostState { return $this->rows[$postId]; }
    public function publish(int $postId): EditorialPostState { $this->publishCalls++; $old = $this->rows[$postId]; return $this->rows[$postId] = new EditorialPostState($old->postId, $old->endpointKey, $old->postType, 'publish', $old->title, $old->content, $old->excerpt, $old->slug, $old->permalink, $old->latestRevisionId + 1, $old->revisionCount + 1); }
    public function trash(int $postId): EditorialPostState { return $this->rows[$postId]; }
    public function restore(int $postId): EditorialPostState { return $this->rows[$postId]; }
}

final class OwnerPublicationFakeDecisionRepository implements OwnerPublicationDecisionRepository
{
    /** @var array<string,OwnerPublicationDecision> */ public array $rows = [];
    public function findByIdempotencyKey(string $key): ?OwnerPublicationDecision { return $this->rows[$key] ?? null; }
    public function findActiveApproval(int $postId, string $token, string $policyVersion, string $blockerFingerprint, string $principalId): ?OwnerPublicationDecision { foreach ($this->rows as $decision) if ($decision->wpPostId === $postId && $decision->editorialStateToken === $token && $decision->policyVersion === $policyVersion && $decision->blockerFingerprint === $blockerFingerprint && $decision->principalId === $principalId) return $decision; return null; }
    public function create(OwnerPublicationDecision $decision): OwnerPublicationDecision { return $this->rows[$decision->idempotencyKey] ??= $decision; }
    public function append(OwnerPublicationDecision $decision): OwnerPublicationDecision { return $this->rows[$decision->idempotencyKey] = $decision; }
}
