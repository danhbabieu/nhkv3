<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt};
use NHK\Core\Infrastructure\Migration\ArticleIngestMigration010;
use NHK\Core\Infrastructure\Article\WpdbArticleOperationReceiptRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class ArticleReceiptRepositoryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new ArticleIngestMigration010())->up();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'nhk_article_operations');
    }

    public function test_receipt_round_trips_and_save_uses_optimistic_revision(): void
    {
        $repository = new WpdbArticleOperationReceiptRepository();
        $receipt = $repository->create(new ArticleOperationReceipt(
            UuidCodec::newV7(), 'receipt-' . bin2hex(random_bytes(4)), str_repeat('a', 64),
            'reconcile', '1:55', 55, 'receipt', ArticleIngestOutcome::GOVERNANCE_PENDING,
            true, ['proposal-1'], [], ['code' => 'APPROVAL_MISSING'], 1,
        ));

        self::assertSame($receipt->idempotencyKey, $repository->findByIdempotencyKey($receipt->idempotencyKey)?->idempotencyKey);
        self::assertSame(1, $repository->findByIdempotencyKey($receipt->idempotencyKey)?->revision);

        $updated = new ArticleOperationReceipt(
            $receipt->operationId, $receipt->idempotencyKey, $receipt->requestFingerprint,
            $receipt->intent, $receipt->wpEndpointKey, $receipt->wpPostId, 'complete',
            ArticleIngestOutcome::COMPLETED, false, $receipt->proposalIds, ['proposal-1'], [], 2,
        );
        self::assertSame(2, $repository->save($updated)->revision);
        self::assertSame('COMPLETED', $repository->findByIdempotencyKey($receipt->idempotencyKey)?->outcome->value);
    }
}
