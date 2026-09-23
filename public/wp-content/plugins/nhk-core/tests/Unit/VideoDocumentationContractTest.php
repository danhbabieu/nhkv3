<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VideoDocumentationContractTest extends TestCase
{
    public function test_active_video_contracts_record_transport_canonical_and_lifecycle_laws(): void
    {
        $paths = [
            __DIR__ . '/../../../../../../docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md',
            __DIR__ . '/../../../../../../docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md',
            __DIR__ . '/../../../../../../docs/mcp/MCP_V3_VIDEO_WORKFLOW.md',
            __DIR__ . '/../../../../../../docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md',
        ];
        $documents = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $paths));

        foreach ([
            'transport outcome',
            'OUTCOME_UNKNOWN',
            'idempotency',
            'canonical Video read-back',
            'optional enrichment',
            'reader-safe',
            'repair',
            'public read-back',
            'generated editorial prose',
        ] as $law) {
            self::assertStringContainsStringIgnoringCase($law, $documents, 'Missing active Video contract law: ' . $law);
        }
    }
}
