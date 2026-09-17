<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\PreferredImageSeoProjection;
use NHK\Core\Domain\Media\MediaSeoStateRegistry;
use PHPUnit\Framework\TestCase;

final class PreferredImageSeoProjectionTest extends TestCase
{
    protected function setUp(): void { self::assertTrue(class_exists(PreferredImageSeoProjection::class), 'Preferred image projection is not implemented.'); }

    public function test_representative_precedence_is_not_replaced_by_newer_evidence(): void
    {
        $result = (new PreferredImageSeoProjection())->project([
            ['role' => 'evidence', 'url' => '/serial.webp', 'created_at' => '2026-09-04'],
            ['role' => 'representative', 'url' => '/front.webp', 'created_at' => '2026-01-01'],
        ]);
        self::assertSame('/front.webp', $result['url']);
    }

    public function test_private_placeholder_and_technical_assets_are_excluded(): void
    {
        $result = (new PreferredImageSeoProjection())->project([
            ['role' => 'representative', 'url' => '/private.webp', 'visibility' => 'PRIVATE'],
            ['role' => 'representative', 'url' => '/placeholder.webp', 'placeholder' => true],
            ['role' => 'technical_detail', 'url' => '/detail.webp'],
        ]);
        self::assertFalse($result['eligible']);
        self::assertContains('REPRESENTATIVE_IMAGE_MISSING', $result['reasons']);
    }

    public function test_ineligible_missing_or_unready_candidates_are_rejected(): void
    {
        $result = (new PreferredImageSeoProjection())->project([
            ['role' => 'representative', 'url' => '/ineligible.webp', 'eligible' => false],
            ['role' => 'representative', 'url' => '/missing.webp', 'state' => MediaSeoStateRegistry::MISSING],
            ['role' => 'representative', 'url' => '/draft.webp', 'readiness' => 'draft'],
        ]);

        self::assertSame(MediaSeoStateRegistry::MISSING, $result['state']);
        self::assertFalse($result['eligible']);
        self::assertNull($result['url']);
    }

    public function test_metadata_source_is_derived_from_actual_fields_not_candidate_label(): void
    {
        $result = (new PreferredImageSeoProjection())->project([
            [
                'role' => 'representative',
                'url' => '/front.webp',
                'metadata_source' => 'UNTRUSTED_LABEL',
                'title' => '',
                'alt' => '',
                'caption' => '',
                'usage_title' => 'Tiêu đề Usage',
                'usage_alt' => 'Alt Usage',
                'subject_caption' => 'Chú thích chủ thể',
                'media_name' => 'Tên Media',
                'attachment_caption' => 'Chú thích Attachment',
            ],
        ]);

        self::assertSame('MEDIA_USAGE', $result['metadata_source']);
        self::assertSame('Tiêu đề Usage', $result['title']);
        self::assertSame('Alt Usage', $result['alt']);
        self::assertSame('Chú thích chủ thể', $result['caption']);
    }
}
