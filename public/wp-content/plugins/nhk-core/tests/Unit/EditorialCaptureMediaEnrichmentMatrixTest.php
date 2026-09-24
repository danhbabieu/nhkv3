<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaEnrichmentBindingRequestBuilder;
use NHK\Core\Domain\Media\MediaUsageRoleRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureMediaEnrichmentMatrixTest extends TestCase
{
    /** @dataProvider ownerMatrix */
    public function test_capture_media_enrichment_builds_generic_binding_for_registered_owner(string $type, string $role): void
    {
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $requests = (new MediaEnrichmentBindingRequestBuilder())->build(
            [['media_id' => $mediaId, 'media_context' => ['role' => $role, 'alt_text' => 'Ảnh minh họa']]],
            ['type' => $type, 'id' => $targetId],
            [],
            'capture-matrix',
        );

        self::assertCount(1, $requests);
        self::assertSame($type, $requests[0]['target']['type']);
        self::assertSame($targetId, $requests[0]['target']['id']);
        self::assertSame($role, $requests[0]['role']);
        self::assertSame('USER_EXPLICIT', $requests[0]['selection_source']);
        self::assertSame('PINNED', $requests[0]['selection_policy']);
        self::assertSame($mediaId, $requests[0]['media']['id']);
    }

    public static function ownerMatrix(): array
    {
        return [
            'brand' => ['brand', MediaUsageRoleRegistry::REPRESENTATIVE],
            'model' => ['model', MediaUsageRoleRegistry::REPRESENTATIVE],
            'variant' => ['variant', MediaUsageRoleRegistry::REPRESENTATIVE],
            'classification' => ['classification', MediaUsageRoleRegistry::TECHNICAL_DETAIL],
            'knowledge' => ['knowledge', MediaUsageRoleRegistry::EVIDENCE],
            'article' => ['wp_post', MediaUsageRoleRegistry::FEATURED_PRIMARY],
            'video' => ['video', MediaUsageRoleRegistry::INLINE_PRIMARY],
            'media' => ['media', MediaUsageRoleRegistry::INLINE_SUPPORTING],
        ];
    }

    public function test_explicit_binding_target_and_role_override_asset_defaults_without_duplicate_selection(): void
    {
        $targetId = UuidCodec::newV7();
        $requests = (new MediaEnrichmentBindingRequestBuilder())->build(
            [['media_id' => UuidCodec::newV7(), 'role' => MediaUsageRoleRegistry::REPRESENTATIVE]],
            ['type' => 'model', 'id' => UuidCodec::newV7()],
            [[
                'media_ref' => ['item_index' => 0],
                'target' => ['type' => 'knowledge', 'id' => $targetId],
                'role' => MediaUsageRoleRegistry::EVIDENCE,
                'selection_source' => 'USER_EXPLICIT',
                'selection_policy' => 'PINNED',
            ]],
            'capture-explicit',
        );

        self::assertCount(1, $requests);
        self::assertSame('knowledge', $requests[0]['target']['type']);
        self::assertSame($targetId, $requests[0]['target']['id']);
        self::assertSame(MediaUsageRoleRegistry::EVIDENCE, $requests[0]['role']);
    }
}
