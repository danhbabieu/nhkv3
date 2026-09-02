<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\{ArticleIngestCoordinator, ArticleIngestPreflight};
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Application\Mcp\{McpArticleIngestHandler, McpToolCatalog};
use NHK\Core\Contracts\Article\{ArticleOperationReceiptRepository, EditorialStateReader};
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt, EditorialPostState};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, PredicateRegistry};
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

}
