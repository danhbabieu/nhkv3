<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Shared\Text\VietnameseSlugNormalizer;

final class MediaFilenameNormalizer
{
    public function __construct(private readonly ?VietnameseSlugNormalizer $slugNormalizer = null)
    {
    }

    public function normalize(string $subject, string $view, string $originalFilename, ?string $uniqueSuffix = null): string
    {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $extension = preg_match('/^(jpe?g|png|webp|avif|gif)$/', $extension) === 1 ? $extension : 'jpg';
        $subject = $this->slug($subject);
        $view = $view === '' ? '' : $this->slug($view);
        $suffix = $this->slug((string) ($uniqueSuffix ?? substr(hash('sha256', $subject . '|' . $view . '|' . $originalFilename), 0, 8)));
        return implode('-', array_values(array_filter([$subject, $view, $suffix], static fn (string $part): bool => $part !== ''))) . '.' . $extension;
    }

    public function normalizeWebp(string $subject, string $view, string $context, ?string $uniqueSuffix = null): string
    {
        $subject = $this->slug($subject);
        $view = $view === '' ? '' : $this->slug($view);
        $parts = $subject !== '' ? [$subject] : [];
        if ($view !== '' && $view !== 'image') $parts[] = $view;
        if ($uniqueSuffix !== null && $uniqueSuffix !== '') $parts[] = $this->slug($uniqueSuffix);
        return implode('-', $parts) . '.webp';
    }

    private function slug(string $value): string
    {
        $result = ($this->slugNormalizer ?? new VietnameseSlugNormalizer(120))->normalize($value);
        return $result->isValid() ? $result->value() : 'media';
    }
}
