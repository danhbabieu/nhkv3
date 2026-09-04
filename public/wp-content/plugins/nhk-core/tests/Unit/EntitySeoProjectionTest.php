<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Seo\EntitySeoProjection;
use PHPUnit\Framework\TestCase;

final class EntitySeoProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(EntitySeoProjection::class), 'Entity SEO projection is not implemented.');
    }

    public function test_registered_entity_projects_profile_and_reuses_supplied_public_url(): void
    {
        $result = (new EntitySeoProjection())->project([
            'type' => 'brand', 'canonical_id' => 'id-1', 'name' => 'Ô Đô',
            'public_url' => '/odo/', 'public_eligible' => true,
            'content_sufficient' => true, 'canonical_identity' => true,
            'public_identity' => true,
        ]);

        self::assertSame('brand', $result['profile']);
        self::assertSame('/odo/', $result['canonical']);
        self::assertTrue($result['indexable']);
    }

    public function test_ambiguous_thin_or_unroutable_entity_fails_closed(): void
    {
        $result = (new EntitySeoProjection())->project([
            'type' => 'model', 'canonical_id' => 'id-2', 'name' => 'Model',
            'public_url' => null, 'public_eligible' => true,
            'content_sufficient' => false, 'canonical_identity' => false,
            'public_identity' => false,
        ]);

        self::assertFalse($result['indexable']);
        self::assertContains('MISSING_PUBLIC_IDENTITY', $result['reasons']);
        self::assertContains('INSUFFICIENT_PUBLIC_CONTENT', $result['reasons']);
    }

    public function test_product_does_not_infer_specimen_from_broad_about_or_payload(): void
    {
        $result = (new EntitySeoProjection())->project([
            'type' => 'product', 'canonical_id' => 'id-3', 'name' => 'Offer',
            'public_url' => '/san-pham/offer/', 'public_eligible' => true,
            'content_sufficient' => true, 'canonical_identity' => true,
            'public_identity' => true, 'about' => ['type' => 'specimen', 'id' => 's-1'],
            'payload' => ['specimen_uuid' => 's-1'],
        ]);

        self::assertArrayNotHasKey('specimen', $result['profile_data']);
        self::assertArrayNotHasKey('specimen', $result);
    }
}
