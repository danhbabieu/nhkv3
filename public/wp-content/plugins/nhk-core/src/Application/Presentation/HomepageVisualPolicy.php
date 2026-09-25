<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/**
 * Read-only presentation policy for compact homepage visuals.
 * It never probes, rewrites or persists a remote source.
 */
final class HomepageVisualPolicy
{
    private const MAX_COMPACT_WIDTH = 320;
    private const MAX_COMPACT_HEIGHT = 320;

    /** @param array<string,mixed> $item @return array<string,mixed> */
    public function resolve(array $item): array
    {
        $url = trim((string) ($item['image_url'] ?? ''));
        $width = is_numeric($item['width'] ?? null) ? (int) $item['width'] : 0;
        $height = is_numeric($item['height'] ?? null) ? (int) $item['height'] : 0;
        $srcset = trim((string) ($item['image_srcset'] ?? $item['srcset'] ?? ''));
        $sizes = trim((string) ($item['image_sizes'] ?? $item['sizes'] ?? ''));
        $type = trim((string) ($item['type'] ?? '')) ?: 'image';

        if ($url === '' || $width < 1 || $height < 1) {
            return $this->fallback($item, $type, 'missing_visual');
        }

        // Canonical Media representative paths are local application-owned
        // delivery, so their source dimensions do not make them remote-card
        // candidates. Responsive delivery, when available, remains attached
        // to the same Media identity.
        if (trim((string) ($item['media_id'] ?? '')) !== '') {
            return $this->image($item, $type, 'representative_local', $url, $width, $height, $srcset, $sizes);
        }

        // An attachment-backed responsive path is safe regardless of source dimensions.
        if ((int) ($item['attachment_id'] ?? 0) > 0 && $srcset !== '') {
            return $this->image($item, $type, 'local_responsive', $url, $width, $height, $srcset, $sizes);
        }

        // A persisted compact candidate is safe for a compact card.
        if ($width <= self::MAX_COMPACT_WIDTH && $height <= self::MAX_COMPACT_HEIGHT) {
            return $this->image($item, $type, 'compact_remote', $url, $width, $height, $srcset, $sizes);
        }

        return $this->fallback($item, $type, 'oversized_remote_fallback');
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function image(array $item, string $type, string $reason, string $url, int $width, int $height, string $srcset, string $sizes): array
    {
        return array_merge($item, [
            'visual_kind' => 'image',
            'visual_type' => $type,
            'visual_reason' => $reason,
            'image_url' => $url,
            'width' => $width,
            'height' => $height,
            'image_srcset' => $srcset,
            'image_sizes' => $sizes,
        ]);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function fallback(array $item, string $type, string $reason): array
    {
        return array_merge($item, [
            'visual_kind' => 'fallback',
            'visual_type' => $type,
            'visual_reason' => $reason,
            'image_url' => null,
            'image_srcset' => '',
            'image_sizes' => '',
            'width' => null,
            'height' => null,
        ]);
    }
}
