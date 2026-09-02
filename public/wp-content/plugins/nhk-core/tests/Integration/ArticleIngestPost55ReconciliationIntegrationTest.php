<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Article\{ArticleIngestCoordinator, ArticleIngestPreflight, ArticleVerificationReader, SemanticProposalPlanner};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Article\{WpEditorialStateReader, WpdbArticleOperationReceiptRepository};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Graph\CoreEndpointResolverRegistrar;
use NHK\Core\Infrastructure\Governance\WpdbProposalRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaRepository, WpdbMediaAssetRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class ArticleIngestPost55ReconciliationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'nhk_article_operations');
    }

    public function test_existing_published_post_55_is_reconciled_without_editorial_mutation(): void
    {
        $reader = new WpEditorialStateReader();
        $before = $reader->read(55);
        if ($before === null || $before->status !== 'publish') self::markTestSkipped('nhk_v3_test has no existing published Post 55 fixture.');

        global $wpdb;
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new WpdbAuthorityRepository($wpdb);
        $media = new WpdbMediaRepository($wpdb);
        $videos = new WpdbVideoRepository($wpdb);
        $claims = new WpdbKnowledgeRepository($wpdb);
        $sources = new WpdbSourceRepository($wpdb);
        $evidence = new WpdbEvidenceRepository($wpdb);
        $endpoints = new EndpointTypeRegistry();
        CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence);
        $proposals = new WpdbProposalRepository($wpdb);
        $coordinator = new ArticleIngestCoordinator(
            new WpdbArticleOperationReceiptRepository($wpdb),
            new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types),
            new SemanticProposalPlanner(),
            $reader,
            new GovernanceService($proposals),
            null,
            $proposals,
            null,
            new ArticleVerificationReader(),
        );
        $key = 'post55-reconcile-' . bin2hex(random_bytes(6));
        $receipt = $coordinator->execute([
            'operation_id' => UuidCodec::newV7(),
            'idempotency_key' => $key,
            'intent' => 'reconcile',
            'target_wp_post' => ['endpoint_type' => 'wp_post', 'endpoint_key' => get_current_blog_id() . ':55'],
            'semantic_bundle' => ['commands' => []],
        ]);
        $after = $reader->read(55);

        self::assertSame('COMPLETED', $receipt->outcome->value);
        self::assertNotNull($after);
        self::assertSame($before->postId, $after->postId);
        self::assertSame($before->status, $after->status);
        self::assertSame($before->title, $after->title);
        self::assertSame($before->content, $after->content);
        self::assertSame($before->excerpt, $after->excerpt);
        self::assertSame($before->slug, $after->slug);
        self::assertSame($before->permalink, $after->permalink);
        self::assertSame($before->latestRevisionId, $after->latestRevisionId);
        self::assertSame($before->revisionCount, $after->revisionCount);
    }
}
