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
            ['type' => 'media', 'stable_key' => 'front-a', 'canonical_uuid' => UuidCodec::newV7(), 'canonical_name' => 'Front A', 'checksum' => $checksum],
            ['type' => 'media', 'stable_key' => 'front-b', 'canonical_uuid' => UuidCodec::newV7(), 'canonical_name' => 'Front B', 'checksum' => $checksum],
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

    public function test_video_dry_run_matches_apply_url_normalization_and_conflict_boundary(): void
    {
        $report = (new DryRunService())->run([
            ['type' => 'video', 'stable_key' => 'video-short-url', 'canonical_uuid' => UuidCodec::newV7(), 'metadata' => ['canonical_url' => 'https://youtu.be/dQw4w9WgXcQ?t=42']],
            ['type' => 'video', 'stable_key' => 'video-mismatched-id', 'canonical_uuid' => UuidCodec::newV7(), 'metadata' => ['canonical_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'external_video_id' => 'oHg5SJYRHA0']],
        ]);

        self::assertSame(1, $report['mapped']);
        self::assertSame(1, $report['conflict']);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $report['items'][0]['normalized_url']);
        self::assertSame('CONFLICT_REQUIRES_REVIEW', $report['items'][1]['reason']);
    }

    public function test_video_dry_run_requires_the_same_canonical_uuid_as_apply(): void
    {
        $report = (new DryRunService())->run([[
            'type' => 'video',
            'stable_key' => 'video-missing-uuid',
            'metadata' => ['canonical_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ]]);

        self::assertSame(1, $report['skipped']);
        self::assertSame('INVALID_IDENTITY', $report['items'][0]['reason']);
    }

    public function test_dry_run_enforces_required_identity_fields_for_apply_backed_domains(): void
    {
        $report = (new DryRunService())->run([
            ['type' => 'media', 'stable_key' => 'media-missing-name', 'canonical_uuid' => UuidCodec::newV7()],
            ['type' => 'knowledge', 'stable_key' => 'claim-missing-text', 'canonical_uuid' => UuidCodec::newV7(), 'metadata' => []],
            ['type' => 'source', 'stable_key' => 'source-missing-title', 'canonical_uuid' => UuidCodec::newV7()],
            ['type' => 'evidence', 'stable_key' => 'evidence-missing-excerpt', 'canonical_uuid' => UuidCodec::newV7(), 'claim_id' => UuidCodec::newV7(), 'source_id' => UuidCodec::newV7()],
            ['type' => 'legacy_media_asset', 'stable_key' => 'asset-missing-mime', 'media_id' => UuidCodec::newV7(), 'storage_key' => 'uploads/missing.webp', 'checksum' => hash('sha256', 'asset')],
        ]);

        self::assertSame(5, $report['skipped']);
        self::assertSame(5, $report['skipped_by_reason']['INVALID_IDENTITY']);
    }

    public function test_nil_canonical_uuid_is_skipped_with_bounded_reason(): void
    {
        $report = (new DryRunService())->run([['type' => 'brand', 'stable_key' => 'odo', 'canonical_uuid' => '00000000-0000-0000-0000-000000000000']]);
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

    public function test_dry_run_rejects_nil_uuid_in_relation_and_url_targets(): void
    {
        $nil = '00000000-0000-0000-0000-000000000000';
        $report = (new DryRunService())->run([
            ['type' => 'relation', 'source_type' => 'brand', 'source_key' => $nil, 'target_type' => 'brand', 'target_key' => UuidCodec::newV7(), 'predicate' => 'about'],
            ['type' => 'url', 'source_path' => '/legacy/entity/', 'target_path' => '/brand/odo/', 'target_entity_type' => 'brand', 'target_entity_id' => $nil, 'target_entity_key' => 'odo'],
        ]);

        self::assertSame(2, $report['skipped']);
        self::assertSame(1, $report['skipped_by_reason']['INVALID_RELATION']);
        self::assertSame(1, $report['skipped_by_reason']['INVALID_URL_MAPPING']);
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

    public function test_domain_targeted_skips_include_explicit_mapping_review_metadata(): void
    {
        $report = (new DryRunService())->run([['type' => 'wp_post', 'stable_key' => 'wp_post:60', 'legacy_type' => 'nhk_brand', 'post_title' => 'Odo']]);
        self::assertSame('DOMAIN_TARGETED', $report['items'][0]['reason']);
        self::assertSame('brand', $report['items'][0]['review']['target_domain']);
        self::assertTrue($report['items'][0]['review']['requires_explicit_mapping']);
        self::assertTrue($report['items'][0]['review']['name_only_match_forbidden']);
        self::assertSame(['EXPLICIT_MAPPING_REQUIRED' => 1], $report['review_by_action']);
    }

    public function test_attachment_and_global_style_skips_include_safe_dispositions(): void
    {
        $report = (new DryRunService())->run([
            ['type' => 'wp_post', 'stable_key' => 'wp_post:31', 'legacy_type' => 'attachment'],
            ['type' => 'wp_post', 'stable_key' => 'wp_post:6', 'legacy_type' => 'wp_global_styles'],
        ]);
        self::assertTrue($report['items'][0]['review']['requires_source_recovery']);
        self::assertSame('media_asset', $report['items'][0]['review']['target_domain']);
        self::assertSame('retire', $report['items'][1]['review']['disposition']);
        self::assertTrue($report['items'][1]['review']['editorial_import_forbidden']);
        self::assertSame(['SOURCE_RECOVERY_REQUIRED' => 1, 'RETIRE_NO_EDITORIAL_IMPORT' => 1], $report['review_by_action']);
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
