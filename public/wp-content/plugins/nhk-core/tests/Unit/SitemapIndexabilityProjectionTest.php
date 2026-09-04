<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Seo\SitemapIndexabilityProjection;
use PHPUnit\Framework\TestCase;

final class SitemapIndexabilityProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(SitemapIndexabilityProjection::class), 'Sitemap indexability projection is not implemented.');
    }

    public function test_only_canonical_indexable_url_is_included(): void
    {
        $result = (new SitemapIndexabilityProjection())->include([
            'canonical_url' => '/odo/', 'rendered_url' => '/odo/', 'readiness' => 'READY',
            'public_eligible' => true, 'indexable' => true,
        ]);

        self::assertTrue($result['included']);
        self::assertSame('/odo/', $result['url']);
    }

    public function test_redirect_history_noindex_private_technical_and_incomplete_are_excluded(): void
    {
        foreach ([
            ['reason' => 'HISTORIC_ROUTE', 'historic' => true],
            ['reason' => 'NOINDEX', 'noindex' => true],
            ['reason' => 'PRIVATE', 'public_eligible' => false],
            ['reason' => 'TECHNICAL_ENDPOINT', 'technical' => true],
            ['reason' => 'INCOMPLETE', 'readiness' => 'INCOMPLETE'],
        ] as $extra) {
            $result = (new SitemapIndexabilityProjection())->include($extra + ['canonical_url' => '/x/', 'readiness' => 'READY', 'public_eligible' => true, 'indexable' => true]);
            self::assertFalse($result['included'], $extra['reason']);
        }
    }

    public function test_lastmod_changes_only_for_owner_or_projection_fingerprint(): void
    {
        $projection = new SitemapIndexabilityProjection();
        $first = $projection->lastmod(null, 'owner-1', 'projection-1');
        self::assertIsString($first);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T/', $first);
        self::assertNull($projection->lastmod($first, 'owner-1', 'projection-1'));
        self::assertIsString($projection->lastmod($first, 'owner-2', 'projection-1'));
    }
}
