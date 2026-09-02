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
}
