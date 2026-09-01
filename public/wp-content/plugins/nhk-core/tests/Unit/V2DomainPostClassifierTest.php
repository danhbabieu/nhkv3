<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Migration\V2DomainPostClassifier;
use PHPUnit\Framework\TestCase;

final class V2DomainPostClassifierTest extends TestCase
{
    public function test_classifies_domain_posts_without_mapping_or_body_import(): void
    {
        $report = (new V2DomainPostClassifier())->run([
            ['type' => 'wp_post', 'legacy_id' => '1', 'legacy_type' => 'nhk_brand', 'post_title' => 'Odo', 'post_content' => 'body'],
            ['type' => 'wp_post', 'legacy_id' => '2', 'legacy_type' => 'nhk_knowledge', 'post_title' => 'A claim'],
            ['type' => 'wp_post', 'legacy_id' => '3', 'legacy_type' => 'nhk_article', 'post_title' => 'Article'],
            ['type' => 'wp_post', 'legacy_id' => '4', 'legacy_type' => 'attachment', 'post_title' => 'Photo'],
            ['type' => 'wp_post', 'legacy_id' => '5', 'legacy_type' => 'wp_global_styles', 'post_title' => 'Styles'],
            ['type' => 'wp_post', 'legacy_id' => '6', 'legacy_type' => 'unknown', 'post_title' => 'Unknown'],
        ]);

        self::assertSame(6, $report['source_count']);
        self::assertSame([
            'EDITORIAL_DEFERRED' => 1,
            'REQUIRES_REVIEW' => 2,
            'RETIRE' => 1,
            'STRUCTURE_REFERENCE' => 2,
        ], $report['counts']);
        self::assertSame('STRUCTURE_REFERENCE', $report['items'][0]['classification']);
        self::assertSame('DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', $report['items'][0]['reason_code']);
        self::assertTrue($report['items'][0]['editorial_import_forbidden']);
        self::assertFalse($report['items'][0]['mapping_applied']);
        self::assertSame('authority.brand', $report['items'][0]['mapping_policy']['target_boundary']);
        self::assertSame('legacy_post_id_to_canonical_uuid', $report['items'][0]['mapping_policy']['identity_rule']);
        self::assertSame('governed_about_edges_only', $report['items'][0]['mapping_policy']['relation_rule']);
        self::assertSame('review_then_governed_mapping', $report['items'][0]['mapping_policy']['migration_action']);
        self::assertSame('REQUIRES_REVIEW', $report['items'][3]['classification']);
        self::assertSame('MEDIA_SOURCE_RECOVERY_OR_RETIREMENT_REQUIRED', $report['items'][3]['reason_code']);
        self::assertSame('RETIRE', $report['items'][4]['classification']);
        self::assertTrue($report['items'][4]['editorial_import_forbidden']);
        self::assertSame('REQUIRES_REVIEW', $report['items'][5]['classification']);
        self::assertSame('UNSUPPORTED_LEGACY_RECORD_REQUIRES_REVIEW', $report['items'][5]['reason_code']);
    }
}
