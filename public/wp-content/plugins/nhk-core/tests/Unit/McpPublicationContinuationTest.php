<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\OwnerPublicationApplicationService;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Application\WordPress\EditorialDraftGateway;
use NHK\Core\Contracts\Article\{ArticleOperationReceiptRepository, OwnerPublicationDecisionRepository, PublicationPrincipal};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState, OwnerPublicationDecision};
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class McpPublicationContinuationTest extends TestCase
{
    public function test_review_approve_and_publish_are_callable_through_mcp_and_owner_service(): void
    {
        $posts = new PublicationContinuationPostStore();
        $decisions = new PublicationContinuationDecisionRepository();
        $owner = new OwnerPublicationApplicationService(
            $posts,
            $decisions,
            static fn (PublicationPrincipal $principal): bool => $principal->id === '0',
        );
        $gateway = new EditorialDraftGateway($posts, new PublicationContinuationReceiptRepository(), $owner);
        $transport = $this->transport($gateway, static fn (string $capability): bool => true);

        $review = $this->call($transport, 'nhk.article.publish.review', [
            'post_id' => 1,
            'expected_state_token' => $posts->rows[1]->token,
            'idempotency_key' => 'continuation-owner-review',
            'evidence' => publicationContinuationEvidence(['real_image_requirements_met' => false, 'real_image_requirements_met_status' => 'missing']),
        ]);
        self::assertSame('OWNER_REVIEW_REQUIRED', $review['outcome']);
        self::assertSame('draft', $posts->rows[1]->status);
        self::assertSame(0, $posts->publishCalls);

        $approved = $this->call($transport, 'nhk.article.publish.approve', [
            'post_id' => 1,
            'expected_state_token' => $posts->rows[1]->token,
            'idempotency_key' => 'continuation-owner-review',
            'decision_id' => $review['decision_id'],
            'affirmation' => 'Đăng',
            'evidence' => publicationContinuationEvidence(['real_image_requirements_met' => false, 'real_image_requirements_met_status' => 'missing']),
        ]);
        self::assertSame('PASS', $approved['outcome']);
        self::assertSame('publish', $approved['post']['status']);

        $stateToken = $posts->rows[2]->token;
        $published = $this->call($transport, 'nhk.article.publish', [
            'post_id' => 2,
            'expected_state_token' => $stateToken,
            'idempotency_key' => 'continuation-direct-pass',
            'evidence' => publicationContinuationEvidence(),
        ]);
        self::assertSame('PASS', $published['outcome']);
        self::assertSame('publish', $published['post']['status']);
        $replay = $this->call($transport, 'nhk.article.publish', [
            'post_id' => 2,
            'expected_state_token' => $stateToken,
            'idempotency_key' => 'continuation-direct-pass',
            'evidence' => publicationContinuationEvidence(),
        ]);
        self::assertSame($published['public_url'], $replay['public_url']);
        self::assertSame(2, $posts->publishCalls);
    }

    public function test_publication_continuation_remains_internal_guarded_and_fail_closed_on_cas_governance_and_idempotency(): void
    {
        $posts = new PublicationContinuationPostStore();
        $owner = new OwnerPublicationApplicationService($posts, new PublicationContinuationDecisionRepository(), static fn (PublicationPrincipal $principal): bool => $principal->id === '0');
        $gateway = new EditorialDraftGateway($posts, new PublicationContinuationReceiptRepository(), $owner);

        $unauthorized = $this->callRaw($this->transport($gateway, static fn (string $capability): bool => $capability !== 'nhk_internal_content_operations'), 'nhk.article.publish', [
            'post_id' => 1, 'expected_state_token' => $posts->rows[1]->token, 'idempotency_key' => 'blocked', 'evidence' => publicationContinuationEvidence(),
        ]);
        self::assertTrue($unauthorized['isError']);
        self::assertSame('DIRECT_WRITE_BLOCKED', $unauthorized['structuredContent']['error']['code']);
        self::assertSame(0, $posts->publishCalls);

        $transport = $this->transport($gateway, static fn (string $capability): bool => true);
        $cas = $this->call($transport, 'nhk.article.publish', [
            'post_id' => 1, 'expected_state_token' => str_repeat('0', 64), 'idempotency_key' => 'cas', 'evidence' => publicationContinuationEvidence(),
        ]);
        self::assertSame('OWNER_REVIEW_REQUIRED', $cas['outcome']);
        self::assertContains('EDITORIAL_CAS_REQUIRED', $cas['diagnostics']);

        $governance = $this->call($transport, 'nhk.article.publish', [
            'post_id' => 1, 'expected_state_token' => $posts->rows[1]->token, 'idempotency_key' => 'governance', 'evidence' => publicationContinuationEvidence(['semantic_readback_verified' => false]),
        ]);
        self::assertSame('OWNER_REVIEW_REQUIRED', $governance['outcome']);
        self::assertContains('SEMANTIC_READBACK_UNVERIFIED', $governance['diagnostics']);
        self::assertSame(0, $posts->publishCalls);

        $missingIdempotency = $this->callRaw($transport, 'nhk.article.publish', [
            'post_id' => 1, 'expected_state_token' => $posts->rows[1]->token, 'idempotency_key' => '', 'evidence' => publicationContinuationEvidence(),
        ]);
        self::assertTrue($missingIdempotency['isError']);
        self::assertSame(-32602, $missingIdempotency['jsonrpcError']['code']);
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function call(McpTransport $transport, string $name, array $arguments): array
    {
        $result = $this->callRaw($transport, $name, $arguments);
        self::assertFalse($result['isError'] ?? true, json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result['structuredContent'];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function callRaw(McpTransport $transport, string $name, array $arguments): array
    {
        $response = $transport->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
        $body = $response['body'] ?? [];
        if (($body['error'] ?? null) !== null) return ['isError' => true, 'jsonrpcError' => $body['error']];
        return (array) ($body['result'] ?? []);
    }

    private function transport(EditorialDraftGateway $gateway, callable $can): McpTransport
    {
        return new McpTransport($this->readHandler(), new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), $can, null, null, null, null, null, $gateway);
    }

    private function readHandler(): McpReadHandler
    {
        return new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class),
            $this->createMock(MediaUsageRepository::class), $this->createMock(VideoRepository::class),
            $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
        );
    }
}

/** @return array<string,mixed> */
function publicationContinuationEvidence(array $overrides = []): array
{
    return array_replace(array_fill_keys([
        'research_acceptable', 'subject_resolved', 'duplicate_intent_handled', 'category_resolved',
        'semantic_plan_complete', 'semantic_readback_verified', 'media_usage_complete',
        'real_image_requirements_met', 'claim_compliance_acceptable', 'seo_projection_valid',
        'internal_links_valid', 'structured_data_valid', 'public_route_ready', 'rendered_public_verification',
    ], true), $overrides);
}

final class PublicationContinuationPostStore implements EditorialPostStore
{
    /** @var array<int,EditorialPostState> */
    public array $rows;
    public int $publishCalls = 0;

    public function __construct()
    {
        $this->rows[1] = new EditorialPostState(1, '1:1', 'post', 'draft', 'Title 1', 'Body 1', '', 'title-1', 'https://example.test/title-1/', 1, 1);
        $this->rows[2] = new EditorialPostState(2, '1:2', 'post', 'draft', 'Title 2', 'Body 2', '', 'title-2', 'https://example.test/title-2/', 1, 1);
    }

    public function read(int $postId): ?EditorialPostState { return $this->rows[$postId] ?? null; }
    public function createDraft(array $fields): EditorialPostState { return $this->rows[1]; }
    public function update(int $postId, array $fields): EditorialPostState { return $this->rows[$postId]; }
    public function publish(int $postId): EditorialPostState
    {
        $this->publishCalls++;
        $old = $this->rows[$postId];
        return $this->rows[$postId] = new EditorialPostState($old->postId, $old->endpointKey, $old->postType, 'publish', $old->title, $old->content, $old->excerpt, $old->slug, $old->permalink, $old->latestRevisionId + 1, $old->revisionCount + 1);
    }
    public function trash(int $postId): EditorialPostState { return $this->rows[$postId]; }
    public function restore(int $postId): EditorialPostState { return $this->rows[$postId]; }
}

final class PublicationContinuationReceiptRepository implements ArticleOperationReceiptRepository
{
    /** @var array<string,ArticleOperationReceipt> */
    public array $rows = [];
    public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->rows[$key] ?? null; }
    public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] ??= $receipt; }
    public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->rows[$receipt->idempotencyKey] = $receipt; }
}

final class PublicationContinuationDecisionRepository implements OwnerPublicationDecisionRepository
{
    /** @var array<string,OwnerPublicationDecision> */
    public array $rows = [];
    public function findByIdempotencyKey(string $key): ?OwnerPublicationDecision { return $this->rows[$key] ?? null; }
    public function findActiveApproval(int $postId, string $token, string $policyVersion, string $blockerFingerprint, string $principalId): ?OwnerPublicationDecision { return null; }
    public function create(OwnerPublicationDecision $decision): OwnerPublicationDecision { return $this->rows[$decision->idempotencyKey] ??= $decision; }
    public function append(OwnerPublicationDecision $decision): OwnerPublicationDecision { return $this->rows[$decision->idempotencyKey] = $decision; }
}
