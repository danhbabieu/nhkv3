<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\PreferredImageSeoProjection;
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
}
