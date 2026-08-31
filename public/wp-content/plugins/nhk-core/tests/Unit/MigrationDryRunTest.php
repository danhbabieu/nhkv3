<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Migration\DryRunService;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MigrationDryRunTest extends TestCase
{
    public function test_dry_run_reports_reason_codes_without_merging_checksum_candidates(): void
    {
        $checksum = hash('sha256', 'same');
        $report = (new DryRunService())->run([
            ['type' => 'brand', 'stable_key' => 'odo', 'canonical_uuid' => UuidCodec::newV7()],
            ['type' => 'media', 'stable_key' => 'front-a', 'checksum' => $checksum],
            ['type' => 'media', 'stable_key' => 'front-b', 'checksum' => $checksum],
            ['type' => 'legacy_widget', 'stable_key' => 'x'],
            ['type' => 'relation', 'source_key' => 'wp_post:1:2'],
            ['type' => 'url', 'source_path' => '/old', 'target_path' => '/new'],
        ]);
        self::assertSame(6, $report['source_count']);
        self::assertSame(4, $report['mapped']);
        self::assertSame(2, $report['skipped']);
        self::assertSame(1, $report['duplicate_candidate']);
        self::assertSame(1, $report['invalid_relation']);
        self::assertSame(1, $report['url_mapping']);
        self::assertSame(['brand' => 1, 'media' => 2, 'legacy_widget' => 1, 'relation' => 1, 'url' => 1], $report['source_counts']);
        self::assertSame(['brand' => 1, 'media' => 2, 'url' => 1], $report['mapped_by_type']);
    }

    public function test_invalid_canonical_uuid_is_skipped_with_bounded_reason(): void
    {
        $report = (new DryRunService())->run([['type' => 'brand', 'stable_key' => 'odo', 'canonical_uuid' => 'not-a-uuid']]);
        self::assertSame('INVALID_IDENTITY', $report['items'][0]['reason']);
    }

    public function test_domain_targeted_url_keeps_explicit_skip_reason(): void
    {
        $report = (new DryRunService())->run([['type' => 'url', 'source_path' => '/knowledge/legacy/', 'target_path' => '', 'target_reason' => 'DOMAIN_TARGETED']]);
        self::assertSame(1, $report['skipped']);
        self::assertSame(1, $report['skipped_by_reason']['DOMAIN_TARGETED']);
        self::assertSame('DOMAIN_TARGETED', $report['items'][0]['reason']);
    }

    public function test_legacy_url_retirement_reasons_are_preserved(): void
    {
        $report = (new DryRunService())->run([
            ['type' => 'url', 'source_path' => '/wp-content/uploads/missing.jpg', 'target_path' => '', 'target_reason' => 'UNSUPPORTED_MEDIA_REFERENCE'],
            ['type' => 'url', 'source_path' => '/wp-global-styles-nhk-v2/', 'target_path' => '', 'target_reason' => 'RETIRED_LEGACY_GARBAGE'],
        ]);
        self::assertSame(1, $report['skipped_by_reason']['UNSUPPORTED_MEDIA_REFERENCE']);
        self::assertSame(1, $report['skipped_by_reason']['RETIRED_LEGACY_GARBAGE']);
    }

    public function test_native_homepage_url_is_a_safe_noop_when_legacy_target_is_empty(): void
    {
        $report = (new DryRunService())->run([[
            'type' => 'url',
            'source_path' => '/',
            'target_path' => '',
            'target_reason' => 'RETIRED_LEGACY_GARBAGE',
        ]]);
        self::assertSame(1, $report['mapped']);
        self::assertSame('READY_NOOP', $report['items'][0]['reason']);
        self::assertSame(1, $report['url_mapping']);
    }

    public function test_url_dry_run_rejects_invalid_path_and_incomplete_entity_target(): void
    {
        $report = (new DryRunService())->run([
            ['type' => 'url', 'source_path' => '/legacy/../unsafe/', 'target_path' => '/new/'],
            ['type' => 'url', 'source_path' => '/legacy/entity/', 'target_path' => '/brand/odo/', 'target_entity_type' => 'brand', 'target_entity_key' => 'odo'],
        ]);
        self::assertSame(0, $report['mapped']);
        self::assertSame(2, $report['skipped']);
        self::assertSame(2, $report['skipped_by_reason']['INVALID_URL_MAPPING']);
    }

    public function test_dry_run_matches_apply_boundaries_for_posts_categories_and_relations(): void
    {
        $uuid = UuidCodec::newV7();
        $report = (new DryRunService())->run([
            ['type' => 'wp_post', 'stable_key' => 'wp_post:1', 'legacy_type' => 'attachment'],
            ['type' => 'wp_post', 'stable_key' => 'wp_post:2', 'legacy_type' => 'wp_global_styles'],
            ['type' => 'wp_post', 'stable_key' => 'wp_post:3', 'legacy_type' => 'nhk_brand'],
            ['type' => 'category', 'stable_key' => 'tag:one', 'taxonomy' => 'post_tag'],
            ['type' => 'relation', 'source_key' => $uuid, 'source_type' => 'article', 'relation_type' => 'about', 'target_key' => $uuid, 'target_type' => 'brand'],
            ['type' => 'relation', 'source_key' => $uuid, 'source_type' => 'knowledge', 'relation_type' => 'authority.brand-model', 'target_key' => $uuid, 'target_type' => 'brand'],
        ]);
        self::assertSame(0, $report['mapped']);
        self::assertSame(6, $report['skipped']);
        self::assertSame(1, $report['skipped_by_reason']['UNSUPPORTED_MEDIA_REFERENCE']);
        self::assertSame(1, $report['skipped_by_reason']['RETIRED_LEGACY_GARBAGE']);
        self::assertSame(1, $report['skipped_by_reason']['DOMAIN_TARGETED']);
        self::assertSame(1, $report['skipped_by_reason']['INVALID_RELATION']);
        self::assertSame(2, $report['skipped_by_reason']['UNSUPPORTED_LEGACY_TYPE']);
    }

    public function test_invalid_checksum_and_non_record_are_not_silently_mapped(): void
    {
        $report = (new DryRunService())->run([
            ['type' => 'media', 'stable_key' => 'front', 'checksum' => 'not-sha256'],
            'invalid-record',
            ['type' => 'brand', 'stable_key' => 'odo', 'conflict' => true],
        ]);
        self::assertSame(0, $report['mapped']);
        self::assertSame(2, $report['skipped']);
        self::assertSame(1, $report['conflict']);
        self::assertSame(1, $report['skipped_by_reason']['INVALID_IDENTITY']);
        self::assertSame(1, $report['skipped_by_reason']['INVALID_RECORD']);
        self::assertSame('CONFLICT_REQUIRES_REVIEW', $report['items'][2]['reason']);
    }

    public function test_semantic_projection_is_mapped_to_metadata_only_context_sink(): void
    {
        $report = (new DryRunService())->run([[
            'type' => 'legacy_semantic_projection',
            'stable_key' => 'sem:projection-fixture:entity_context',
            'legacy_id' => '17',
            'semantic_id' => 'sem:projection-fixture',
            'legacy_type' => 'ENTITY_CONTEXT',
            'canonical_object_type' => 'brand',
            'canonical_object_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]]);
        self::assertSame(1, $report['mapped']);
        self::assertSame('READ_ONLY_CONTEXT_READY', $report['items'][0]['reason']);
    }

    public function test_semantic_projection_body_is_fail_closed(): void
    {
        $report = (new DryRunService())->run([[
            'type' => 'legacy_semantic_projection',
            'stable_key' => 'sem:body-fixture',
            'legacy_id' => '18',
            'legacy_type' => 'ENTITY_CONTEXT',
            'canonical_object_type' => 'brand',
            'canonical_object_id' => '550e8400-e29b-41d4-a716-446655440000',
            'body' => 'must not migrate',
        ]]);
        self::assertSame(1, $report['skipped']);
        self::assertSame('PROJECTION_BODY_FORBIDDEN', $report['items'][0]['reason']);
    }
}
