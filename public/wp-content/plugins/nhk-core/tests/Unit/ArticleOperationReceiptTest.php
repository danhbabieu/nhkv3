<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use InvalidArgumentException;
use NHK\Core\Domain\Article\ArticleIngestOutcome;
use NHK\Core\Domain\Article\ArticleOperationReceipt;
use PHPUnit\Framework\TestCase;

final class ArticleOperationReceiptTest extends TestCase
{
    public function test_receipt_rejects_empty_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ArticleOperationReceipt('', 'key', str_repeat('a', 64), 'reconcile', null, null, 'receipt', ArticleIngestOutcome::COMPLETED, false, [], [], [], 1);
    }

    public function test_valid_receipt_serializes_operation_state_without_body(): void
    {
        $receipt = new ArticleOperationReceipt(
            '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22',
            'article:55:reconcile:1',
            str_repeat('a', 64),
            'reconcile',
            '1:55',
            55,
            'receipt',
            ArticleIngestOutcome::COMPLETED,
            false,
            ['proposal-1'],
            ['proposal-1'],
            ['state_token' => 'opaque'],
            1,
        );

        $state = $receipt->toArray();

        self::assertSame('COMPLETED', $state['outcome']);
        self::assertSame(['proposal-1'], $state['proposal_ids']);
        self::assertArrayNotHasKey('body', $state);
        self::assertArrayHasKey('dependency_map', $state);
        self::assertArrayHasKey('proposal_states', $state);
        self::assertArrayHasKey('apply_attempts', $state);
    }

    public function test_receipt_preserves_editorial_state_token_as_operational_metadata(): void
    {
        $receipt = new ArticleOperationReceipt(
            '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22', 'key-token', str_repeat('b', 64),
            'reconcile', '1:55', 55, 'preflight', ArticleIngestOutcome::GOVERNANCE_PENDING,
            true, [], [], [], 1, null, null, 'editorial-token',
        );

        self::assertSame('editorial-token', $receipt->toArray()['wp_state_token']);
        self::assertArrayNotHasKey('body', $receipt->toArray());
    }
}
