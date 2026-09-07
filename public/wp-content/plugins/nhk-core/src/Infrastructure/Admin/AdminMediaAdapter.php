<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Media\Media;

final class AdminMediaAdapter
{
    /** @param iterable<Media> $media */
    public function __construct(private iterable $media) {}

    /** @return list<array<string,mixed>> */
    public function find(string $query = ''): array
    {
        $query = strtolower(trim($query)); $rows = [];
        foreach ($this->media as $item) {
            if (!$item instanceof Media) continue;
            $haystack = strtolower($item->canonicalName . ' ' . $item->stableKey . ' ' . $item->canonicalId);
            if ($query !== '' && !str_contains($haystack, $query)) continue;
            $rows[] = ['id' => $item->canonicalId, 'title' => $item->canonicalName, 'stable_key' => $item->stableKey, 'readiness' => $item->readiness, 'active' => $item->active, 'revision' => $item->revision, 'placeholder' => $item->isSystemPlaceholder()];
        }
        return $rows;
    }
}
