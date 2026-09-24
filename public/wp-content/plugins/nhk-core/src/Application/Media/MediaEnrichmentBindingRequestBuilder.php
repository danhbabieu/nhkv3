<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaUsageRoleRegistry;

/** Builds governed MediaBinding requests from a Capture's explicit media intent. */
final class MediaEnrichmentBindingRequestBuilder
{
    /**
     * @param list<array<string,mixed>> $assets
     * @param array<string,mixed> $primary
     * @param list<array<string,mixed>> $explicitBindings
     * @return list<array<string,mixed>>
     */
    public function build(array $assets, array $primary, array $explicitBindings, string $captureId): array
    {
        $bindings = [];
        foreach ($explicitBindings as $index => $binding) {
            if (!is_array($binding)) continue;
            $target = is_array($binding['target'] ?? null) ? $binding['target'] : $primary;
            $mediaRef = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
            $asset = isset($mediaRef['item_index']) && is_array($assets[(int) $mediaRef['item_index']] ?? null)
                ? $assets[(int) $mediaRef['item_index']]
                : (is_array($assets[$index] ?? null) ? $assets[$index] : []);
            $request = $this->request($asset, $target, $binding, $captureId, $index);
            if ($request !== null) $bindings[] = $request;
        }
        if ($bindings !== []) return $bindings;

        foreach ($assets as $index => $asset) {
            if (!is_array($asset)) continue;
            $request = $this->request($asset, $primary, [], $captureId, $index);
            if ($request !== null) $bindings[] = $request;
        }
        return $bindings;
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $target @param array<string,mixed> $binding @return array<string,mixed>|null */
    private function request(array $asset, array $target, array $binding, string $captureId, int $index): ?array
    {
        $mediaId = trim((string) (($binding['media_ref']['media_id'] ?? null) ?: ($asset['media_id'] ?? '')));
        $type = trim((string) ($target['type'] ?? ''));
        $id = trim((string) ($target['id'] ?? ''));
        if ($mediaId === '' || $type === '' || $id === '') return null;
        $context = is_array($asset['media_context'] ?? null) ? $asset['media_context'] : [];
        $role = strtolower(trim((string) ($binding['role'] ?? $context['role'] ?? $asset['role'] ?? MediaUsageRoleRegistry::REPRESENTATIVE)));
        $source = strtoupper(trim((string) ($binding['selection_source'] ?? $asset['selection_source'] ?? 'USER_EXPLICIT')));
        $policy = strtoupper(trim((string) ($binding['selection_policy'] ?? $asset['selection_policy'] ?? 'PINNED')));
        return [
            'idempotency_key' => trim((string) ($binding['idempotency_key'] ?? '')) ?: $captureId . ':media-binding:' . $index,
            'media' => ['id' => $mediaId],
            'target' => ['type' => $type, 'id' => $id],
            'role' => $role,
            'selection_source' => $source,
            'selection_policy' => $policy,
            'placement_key' => (string) ($binding['placement_key'] ?? $asset['placement_key'] ?? $context['placement_key'] ?? ''),
            'sort_order' => (int) ($binding['sort_order'] ?? $asset['sort_order'] ?? 0),
            'seo' => [
                'alt_text' => (string) ($binding['seo']['alt_text'] ?? $context['alt_text'] ?? ''),
                'caption' => (string) ($binding['seo']['caption'] ?? $context['caption'] ?? ''),
                'title' => (string) ($binding['seo']['title'] ?? $context['title'] ?? ''),
            ],
        ];
    }
}
