<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;

final class MediaFilenameNormalizer
{
    private CanonicalPublicSlugPolicy $slugs;

    public function __construct(?CanonicalPublicSlugPolicy $slugs = null)
    {
        $this->slugs = $slugs ?? new CanonicalPublicSlugPolicy();
    }

    public function normalize(string $subject, string $view, string $originalFilename, ?string $uniqueSuffix = null): string
    {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $extension = preg_match('/^(jpe?g|png|webp|avif|gif)$/', $extension) === 1 ? $extension : 'jpg';
        $subject = $this->slug($subject);
        $view = $view === '' ? '' : $this->slug($view);
        $parts = array_values(array_filter([$subject, $view], static fn(string $part): bool => $part !== ''));
        if ($uniqueSuffix !== null && trim($uniqueSuffix) !== '') {
            $suffix = $this->slug($uniqueSuffix);
            if ($suffix !== '') $parts[] = $suffix;
        }
        if ($parts === []) $parts[] = 'media';
        return implode('-', $parts) . '.' . $extension;
    }

    public function normalizeWebp(string $subject, string $view, string $context, ?string $uniqueSuffix = null): string
    {
        $subject = $this->slug($subject);
        $view = $view === '' ? '' : $this->slug($view);
        $parts = [$subject !== '' ? $subject : 'media'];
        if ($view !== '' && $view !== 'image') $parts[] = $view;
        if ($uniqueSuffix !== null && trim($uniqueSuffix) !== '') {
            $suffix = $this->slug($uniqueSuffix);
            if ($suffix !== '') $parts[] = $suffix;
        }
        return trim(implode('-', $parts), '-') . '.webp';
    }

    private function slug(string $value): string
    {
        return $this->slugs->slug($value);
    }
}
