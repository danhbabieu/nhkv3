<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleDiagnosticReader;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt};
use PHPUnit\Framework\TestCase;

final class ArticleDiagnosticReaderTest extends TestCase
{
    public function test_diagnostic_exposes_retry_state_without_editorial_body(): void
    {
        $receipt = new ArticleOperationReceipt(
            '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22', 'diagnostic-1', str_repeat('c', 64),
            'reconcile', '1:55', 55, 'governance', ArticleIngestOutcome::GOVERNANCE_PENDING,
            true, ['proposal-1'], [], ['code' => 'APPROVAL_MISSING'], 1, null, null, 'token',
        );

        $diagnostic = (new ArticleDiagnosticReader())->describe($receipt, ['verification' => ['post_content' => 'full article body', 'status' => 'publish']]);

        self::assertTrue($diagnostic['retryable']);
        self::assertSame('APPROVAL_MISSING', $diagnostic['last_failure']['code']);
        self::assertArrayNotHasKey('body', $diagnostic);
        self::assertArrayNotHasKey('post_content', $diagnostic['verification']);
        self::assertSame('publish', $diagnostic['verification']['status']);
    }

    public function test_read_only_diagnostic_keeps_supplied_capture_media_completion_and_public_evidence(): void
    {
        $receipt = new ArticleOperationReceipt(
            '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22', 'diagnostic-711', str_repeat('d', 64),
            'reconcile', '1:711', 711, 'media', ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE,
            true, [], [], ['code' => 'MEDIAUSAGE_INCOMPLETE'], 1, null, null, 'token',
        );

        $diagnostic = (new ArticleDiagnosticReader())->describe($receipt, [
            'capture_id' => '01a0d5c4-35b1-7ac6-b060-971bdf6dad85',
            'article_id' => 711,
            'media_usage' => ['status' => 'PARTIAL', 'media_ids' => ['media-708', 'media-709', 'media-710'], 'body' => 'not exposed'],
            'completion' => ['relation_or_usage_state' => 'PARTIAL'],
            'public_projection' => ['status' => 'BLOCKED'],
        ]);

        self::assertSame('01a0d5c4-35b1-7ac6-b060-971bdf6dad85', $diagnostic['capture_id']);
        self::assertSame(711, $diagnostic['article_id']);
        self::assertSame(['media-708', 'media-709', 'media-710'], $diagnostic['media_usage']['media_ids']);
        self::assertArrayNotHasKey('body', $diagnostic['media_usage']);
        self::assertSame('PARTIAL', $diagnostic['completion']['relation_or_usage_state']);
        self::assertSame('BLOCKED', $diagnostic['public_projection']['status']);
    }
}
