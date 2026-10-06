<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Knowledge\PublicResearchSourceDisplayPolicy;
use NHK\Core\Domain\Knowledge\Source;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class PublicResearchSourceDisplayPolicyTest extends TestCase
{
    public function test_vietnamese_host_is_not_eligible_for_public_source_display(): void
    {
        $source = $this->source('https://example.vn/reference');

        self::assertSame([
            'eligible' => false,
            'classification' => 'VIETNAMESE',
            'reason' => 'PUBLIC_SOURCE_HOST_IS_VIETNAMESE',
        ], (new PublicResearchSourceDisplayPolicy())->evaluate($source));
        self::assertFalse((new PublicResearchSourceDisplayPolicy())->allows($source));
    }

    public function test_known_vietnamese_publisher_is_not_eligible(): void
    {
        $source = $this->source('https://vangvong.com/shop/dong-ho');

        self::assertSame([
            'eligible' => false,
            'classification' => 'VIETNAMESE',
            'reason' => 'PUBLIC_SOURCE_PUBLISHER_IS_VIETNAMESE',
        ], (new PublicResearchSourceDisplayPolicy())->evaluate($source));
    }

    public function test_international_public_source_is_eligible(): void
    {
        $source = $this->source('https://junghansarchiv.de/en/catalogues');

        self::assertSame([
            'eligible' => true,
            'classification' => 'INTERNATIONAL',
            'reason' => 'PUBLIC_SOURCE_ALLOWED',
        ], (new PublicResearchSourceDisplayPolicy())->evaluate($source));
    }

    public function test_explicit_vietnamese_metadata_is_not_eligible(): void
    {
        $source = $this->source('https://archive.example/reference', ['country_code' => 'VN']);

        self::assertSame([
            'eligible' => false,
            'classification' => 'VIETNAMESE',
            'reason' => 'PUBLIC_SOURCE_METADATA_IS_VIETNAMESE',
        ], (new PublicResearchSourceDisplayPolicy())->evaluate($source));
    }

    public function test_private_source_is_not_eligible_before_source_classification(): void
    {
        $source = $this->source('https://junghansarchiv.de/en/catalogues', ['visibility' => 'PRIVATE']);

        self::assertSame([
            'eligible' => false,
            'classification' => 'NOT_PUBLIC',
            'reason' => 'SOURCE_IS_NOT_PUBLIC',
        ], (new PublicResearchSourceDisplayPolicy())->evaluate($source));
    }

    public function test_non_web_catalogue_locator_is_allowed_and_source_is_unchanged(): void
    {
        $source = $this->source('Gustav Becker Hauptkatalog 1912, plate 42', ['visibility' => 'PUBLIC'], 'catalog');
        $before = [$source->canonicalId, $source->stableKey, $source->title, $source->locator, $source->metadata];

        self::assertSame([
            'eligible' => true,
            'classification' => 'INTERNATIONAL',
            'reason' => 'PUBLIC_SOURCE_ALLOWED',
        ], (new PublicResearchSourceDisplayPolicy())->evaluate($source));
        self::assertSame($before, [$source->canonicalId, $source->stableKey, $source->title, $source->locator, $source->metadata]);
    }

    private function source(string $locator, array $metadata = [], string $type = 'website'): Source
    {
        return new Source(UuidCodec::newV7(), 'nhk:source:policy-test:' . UuidCodec::newV7(), 'Policy test source', $type, $locator, array_merge(['visibility' => 'PUBLIC'], $metadata));
    }
}
