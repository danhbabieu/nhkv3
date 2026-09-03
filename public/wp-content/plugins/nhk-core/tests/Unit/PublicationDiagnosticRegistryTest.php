<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Article\{ArticlePublicationOutcome, PublicationDiagnosticRegistry};
use PHPUnit\Framework\TestCase;

final class PublicationDiagnosticRegistryTest extends TestCase
{
    public function test_unknown_diagnostic_fails_closed_and_quality_diagnostic_is_owner_reviewable(): void
    {
        self::assertSame(ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED, PublicationDiagnosticRegistry::classify(['REAL_IMAGE_INCOMPLETE']));
        self::assertSame(ArticlePublicationOutcome::SYSTEM_BLOCKED, PublicationDiagnosticRegistry::classify(['UNKNOWN_CODE']));
        self::assertSame(PublicationDiagnosticRegistry::fingerprint(['B', 'A']), PublicationDiagnosticRegistry::fingerprint(['A', 'B']));
    }
}
