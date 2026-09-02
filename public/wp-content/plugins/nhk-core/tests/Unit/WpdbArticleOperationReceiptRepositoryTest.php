<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Article\WpdbArticleOperationReceiptRepository;
use PHPUnit\Framework\TestCase;

final class WpdbArticleOperationReceiptRepositoryTest extends TestCase
{
    public function test_repository_hydrates_a_receipt_by_idempotency_key(): void
    {
        $database = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, mixed ...$args): string { return $query . serialize($args); }
            public function get_row(string $query, int $output): array { return [
                'operation_id' => '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22',
                'idempotency_key' => 'key',
                'request_fingerprint' => str_repeat('a', 64),
                'intent' => 'reconcile',
                'wp_endpoint_key' => '1:55',
                'wp_post_id' => '55',
                'stage' => 'receipt',
                'outcome' => 'GOVERNANCE_PENDING',
                'retryable' => '1',
                'proposal_ids_json' => '["proposal-1"]',
                'applied_proposal_ids_json' => '[]',
                'failure_json' => '{"code":"APPROVAL_MISSING"}',
                'revision' => '1',
                'created_at' => null,
                'updated_at' => null,
            ]; }
        };

        $receipt = (new WpdbArticleOperationReceiptRepository($database))->findByIdempotencyKey('key');

        self::assertNotNull($receipt);
        self::assertSame('1:55', $receipt->wpEndpointKey);
        self::assertSame(['proposal-1'], $receipt->proposalIds);
        self::assertSame('APPROVAL_MISSING', $receipt->failure['code']);
    }
}
