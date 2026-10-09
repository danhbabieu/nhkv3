<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureSemanticPreflight;
use PHPUnit\Framework\TestCase;

final class CaptureSemanticPreflightTest extends TestCase
{
    public function test_preflight_reports_shared_checks_without_promoting_pending_dependencies_to_success(): void
    {
        $result = (new CaptureSemanticPreflight())->evaluate(
            ['intent' => 'VIDEO', 'video' => ['url' => 'https://youtu.be/aBcDeFgHiJk'], 'subject_hints' => ['unresolved hint']],
            ['entity_mentions' => ['unresolved hint']],
            ['status' => 'resolved', 'intent' => 'VIDEO'],
            ['status' => 'resolved', 'primary' => ['id' => '5c1cb4f1-3e5f-4c0e-9b4c-3dc6d7f1e4f7', 'type' => 'classification', 'revision' => 4]],
        );

        self::assertSame(['intent', 'source', 'canonical_identity', 'duplicate_reuse', 'subject_compatibility', 'provenance', 'scope', 'evidence', 'graph_eligibility', 'content_relevance'], array_keys($result['checks']));
        self::assertSame('READY', $result['checks']['intent']['status']);
        self::assertSame('READY', $result['checks']['source']['status']);
        self::assertSame('READY', $result['checks']['canonical_identity']['status']);
        self::assertSame('NOT_APPLICABLE', $result['checks']['graph_eligibility']['status']);
        self::assertSame('INCOMPLETE', $result['status']);
        self::assertContains('CONTENT_RELEVANCE_PENDING', $result['checks']['content_relevance']['reason_codes']);
    }
}
