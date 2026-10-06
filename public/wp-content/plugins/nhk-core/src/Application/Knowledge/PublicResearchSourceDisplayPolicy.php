<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Domain\Knowledge\Source;

/**
 * Reader-facing source-display policy for public research projections.
 *
 * This is deliberately stateless: it classifies a source for display only;
 * it never changes the source or its retained provenance.
 */
final class PublicResearchSourceDisplayPolicy
{
    /**
     * @return array{eligible: bool, classification: string, reason: string}
     */
    public function evaluate(Source $source): array
    {
        if (!$source->active) {
            return [
                'eligible' => false,
                'classification' => 'NOT_PUBLIC',
                'reason' => 'SOURCE_IS_INACTIVE',
            ];
        }

        if (!$source->isPublic()) {
            return [
                'eligible' => false,
                'classification' => 'NOT_PUBLIC',
                'reason' => 'SOURCE_IS_NOT_PUBLIC',
            ];
        }

        if ($this->hasVietnameseMetadata($source->metadata)) {
            return [
                'eligible' => false,
                'classification' => 'VIETNAMESE',
                'reason' => 'PUBLIC_SOURCE_METADATA_IS_VIETNAMESE',
            ];
        }

        $host = $this->host($source->locator);
        if ($host !== null && ($host === 'vangvong.com' || str_ends_with($host, '.vangvong.com'))) {
            return [
                'eligible' => false,
                'classification' => 'VIETNAMESE',
                'reason' => 'PUBLIC_SOURCE_PUBLISHER_IS_VIETNAMESE',
            ];
        }

        if ($host !== null && ($host === 'vn' || str_ends_with($host, '.vn'))) {
            return [
                'eligible' => false,
                'classification' => 'VIETNAMESE',
                'reason' => 'PUBLIC_SOURCE_HOST_IS_VIETNAMESE',
            ];
        }

        return [
            'eligible' => true,
            'classification' => 'INTERNATIONAL',
            'reason' => 'PUBLIC_SOURCE_ALLOWED',
        ];
    }

    public function allows(Source $source): bool
    {
        return $this->evaluate($source)['eligible'];
    }

    private function hasVietnameseMetadata(array $metadata): bool
    {
        foreach (['country_code', 'origin_country', 'publisher_country', 'public_source_region'] as $key) {
            $value = strtoupper(trim((string) ($metadata[$key] ?? '')));
            if (in_array($value, ['VN', 'VI', 'VIETNAMESE'], true)) {
                return true;
            }
        }

        return false;
    }

    private function host(?string $locator): ?string
    {
        if ($locator === null || filter_var($locator, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower(trim((string) parse_url($locator, PHP_URL_HOST)));
        return $host === '' ? null : rtrim($host, '.');
    }
}
