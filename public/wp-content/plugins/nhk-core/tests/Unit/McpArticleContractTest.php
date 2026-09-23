<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\{ArticleIngestCoordinator, ArticleIngestPreflight};
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Application\Article\ArticleResearchPreflight;
use NHK\Core\Application\Mcp\{McpArticleIngestHandler, McpToolCatalog};
use NHK\Core\Application\Article\ArticleReconciliationOrchestrator;
use NHK\Core\Contracts\Article\{ArticleOperationReceiptRepository, EditorialStateReader};
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, PredicateRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class McpArticleContractTest extends TestCase
{
    public function test_article_ingest_returns_explicit_unsupported_outcome_without_target_or_editorial_writer(): void
    {
        $receipts = new class implements ArticleOperationReceiptRepository {
            /** @var array<string,ArticleOperationReceipt> */ public array $items = [];
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->items[$key] ?? null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
        };
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', []));
        $reader = new class implements EditorialStateReader { public function read(int $postId): ?EditorialPostState { throw new \RuntimeException('editorial reader must not be called for unsupported create'); } };
        $preflight = new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types);
        $handler = new McpArticleIngestHandler(new ArticleIngestCoordinator($receipts), $preflight, $reader);

        $result = $handler->ingest(['idempotency_key' => 'create-only', 'intent' => 'create']);

        self::assertSame(ArticleIngestOutcome::UNSUPPORTED_OPERATION->value, $result['outcome']);
        self::assertFalse($result['retryable']);
    }

    public function test_article_preflight_is_read_only_and_returns_opaque_editorial_token(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $reader = new class implements EditorialStateReader { public function read(int $postId): ?EditorialPostState { return new EditorialPostState(55, '1:55', 'post', 'publish', 'T', 'body that must not be returned', '', 't', 'https://example.test/t/', 0, 0); } };
        $preflight = new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types);
        $receipts = new class implements ArticleOperationReceiptRepository {
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $receipt; }
        };
        $handler = new McpArticleIngestHandler(new ArticleIngestCoordinator($receipts), $preflight, $reader);

        $result = $handler->preflight(['intent' => 'reconcile', 'target_wp_post' => ['endpoint_type' => 'wp_post', 'endpoint_key' => '1:55'], 'semantic_bundle' => ['commands' => []]]);

        self::assertTrue($result['accepted']);
        self::assertArrayHasKey('wp_state_token', $result['details']);
        self::assertArrayNotHasKey('body', $result);
    }

    public function test_catalog_contains_two_coordinated_article_tools(): void
    {
        $names = array_column(McpToolCatalog::tools(), 'name');
        self::assertContains('nhk.article.preflight', $names);
        self::assertContains('nhk.article.ingest', $names);
        self::assertTrue(McpToolCatalog::isGoverned('nhk.article.ingest'));
        self::assertFalse(McpToolCatalog::isGoverned('nhk.article.preflight'));
        self::assertContains('nhk_ingest_articles', GovernanceCapabilities::ALL);
    }

    public function test_public_research_preflight_preserves_explicit_media_selection(): void
    {
        $capturedContext = null;
        $research = new ArticleResearchPreflight(
            static fn (array $input): array => [
                'status' => 'resolved',
                'primary' => ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'type' => 'model', 'name' => 'Odo 36'],
                'subjects' => [['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'type' => 'model', 'name' => 'Odo 36']],
            ],
            static function (array $input) use (&$capturedContext): array {
                $capturedContext = $input['article_context'] ?? null;
                $selected = $capturedContext['article_media']['selected']['media_id'] ?? null;
                return [
                    'status' => 'available',
                    'posts' => [['id' => '1:55', 'title' => 'Existing', 'subject_ids' => ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']]],
                    'current_categories' => [['id' => 2, 'name' => 'Đồng hồ', 'slug' => 'dong-ho']],
                    'categories' => [['id' => 2, 'name' => 'Đồng hồ', 'slug' => 'dong-ho']],
                    'article_media' => [
                        'featured_primary' => ['media_id' => $selected, 'placeholder' => false, 'valid_for_completeness' => true],
                        'inline_primary' => ['media_id' => $selected, 'placeholder' => false, 'valid_for_completeness' => true],
                        'media_complete' => true,
                    ],
                    'media' => [], 'knowledge' => [], 'sources' => [], 'evidence' => [], 'relations' => [], 'videos' => [],
                ];
            },
            static fn (array $relation): array => ['eligible' => true, 'route' => '/dong-ho/'],
        );
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $reader = new class implements EditorialStateReader { public function read(int $postId): ?EditorialPostState { return null; } };
        $receipts = new class implements ArticleOperationReceiptRepository {
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $receipt; }
        };
        $handler = new McpArticleIngestHandler(new ArticleIngestCoordinator($receipts), new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types), $reader, research: $research);
        $mediaId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $result = $handler->preflight([
            'intent' => 'reconcile',
            'research_topic' => 'Odo 36/10',
            'research_subject' => ['exact' => ['name' => 'Odo 36', 'entity_type' => 'model']],
            'target_wp_post' => ['endpoint_type' => 'wp_post', 'endpoint_key' => '1:55'],
            'article_media' => ['selected' => ['media_id' => $mediaId, 'role' => 'featured_primary', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED']],
        ]);

        self::assertSame($mediaId, $capturedContext['article_media']['selected']['media_id']);
        self::assertSame($mediaId, $result['media_plan']['featured_primary']['media_id']);
        self::assertFalse($result['media_plan']['featured_primary']['placeholder']);
    }

    public function test_preflight_and_ingest_share_the_same_reconciliation_plan_and_structured_media_request(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $reader = new class implements EditorialStateReader { public function read(int $postId): ?EditorialPostState { return new EditorialPostState($postId, '1:' . $postId, 'post', 'draft', 'Existing', '', '', 'existing', 'https://example.test/existing/', 0, 0); } };
        $receipts = new class implements ArticleOperationReceiptRepository { public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return null; } public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $receipt; } public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $receipt; } };
        $receipt = new ArticleOperationReceipt(UuidCodec::newV7(), 'reconcile-runtime', hash('sha256', 'request'), 'reconcile', '1:55', 55, 'complete', ArticleIngestOutcome::COMPLETED, false);
        $coordinator = new ArticleIngestCoordinator($receipts);
        $seen = [];
        $orchestrator = new ArticleReconciliationOrchestrator(
            static fn (array $input): array => ['post_id' => $input['post_id'], 'desired_media' => $input['article_media'] ?? []],
            static fn (array $state): array => ['intent' => 'IMAGE_ARTICLE'],
            static fn (array $state): array => ['canonical_subject_id' => 'subject'],
            static function (array $state) use (&$seen): array { $seen[] = $state['desired_media']; return ['diagnostics' => ['MEDIA_USAGE_SEMANTIC_MISMATCH']]; },
            static fn (array $state, array $actions): array => [], static fn (array $state): array => ['outcome' => 'PASS'],
            static fn (array $state): array => [], static fn (array $state): array => ['status' => 'verified'],
        );
        $handler = new McpArticleIngestHandler($coordinator, new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types), $reader, reconciliationFactory: static fn (): ArticleReconciliationOrchestrator => $orchestrator);
        $input = ['intent' => 'reconcile', 'idempotency_key' => 'reconcile-runtime', 'target_wp_post' => ['endpoint_type' => 'wp_post', 'endpoint_key' => '1:55'], 'article_media' => ['selected' => ['media_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'role' => 'featured_primary', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED']]];

        $preflight = $handler->preflight($input);
        $ingest = $handler->ingest($input);

        self::assertSame('REPLACE_FEATURED_MEDIA', $preflight['reconciliation']['actions'][0]['action']);
        self::assertSame($preflight['reconciliation']['actions'], $ingest['reconciliation']['actions']);
        self::assertSame($input['article_media'], $seen[0]);
        self::assertSame($input['article_media'], $seen[1]);
    }

}
