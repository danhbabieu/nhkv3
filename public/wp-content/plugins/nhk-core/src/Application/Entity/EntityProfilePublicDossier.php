<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\AuthorityEntity;

/**
 * Shared public-dossier adapter. It adds presentation/readiness descriptors to
 * the existing dossier packet; it does not query Graph or copy owner data.
 */
final class EntityProfilePublicDossier
{
    public function __construct(private EntityProfileReadFoundation $foundation) {}

    /** @return array<string,mixed> */
    public function forEntity(AuthorityEntity $entity, ?string $canonicalPath = null): array
    {
        $packet = $this->foundation->forEntity($entity);
        if (($packet['status'] ?? '') !== 'AVAILABLE') return $packet;

        $definition = is_array($packet['entity_profile'] ?? null) ? $packet['entity_profile'] : [];
        $profile = (string) ($definition['key'] ?? '');
        $packet['public_dossier'] = [
            'status' => 'AVAILABLE',
            'profile' => $profile,
            'sections' => $this->sections($packet, $profile),
            'presentation' => is_array($definition['presentation'] ?? null) ? $definition['presentation'] : [],
        ];
        if ($canonicalPath !== null && $canonicalPath !== '') $this->applyCanonicalPath($packet, $canonicalPath);
        return $packet;
    }

    /** @return array<string,array<string,mixed>> */
    private function sections(array $packet, string $profile): array
    {
        $sections = [
            'identity' => $this->itemsStatus(is_array($packet['identity'] ?? null) ? [$packet['identity']] : []),
            'knowledge' => $this->itemsStatus(is_array($packet['knowledge']['facets'] ?? null) ? $packet['knowledge']['facets'] : [], (string) ($packet['knowledge']['status'] ?? 'UNAVAILABLE')),
            'media' => $this->itemsStatus(is_array($packet['media_gallery'] ?? null) ? $packet['media_gallery'] : []),
            'video' => $this->relationStatus($packet, 'videos'),
            'articles' => $this->relationStatus($packet, 'articles'),
        ];

        foreach (['classifications', 'models', 'variants', 'specimens', 'products'] as $group) $sections[$group] = $this->relationStatus($packet, $group);
        if ($profile === 'clock_type') {
            // Reverse Brand traversal is not available in the current shared
            // two-hop reader. Empty is not an honest representation of that gap.
            $sections['brands'] = ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'reason' => 'DERIVED_BRAND_REVERSE_TRAVERSAL_UNAVAILABLE', 'items' => []];
        } else {
            $sections['brands'] = $this->relationStatus($packet, 'brands');
        }
        return $sections;
    }

    /** @return array<string,mixed> */
    private function relationStatus(array $packet, string $group): array
    {
        $relations = $packet['relation_sections'] ?? null;
        if (!is_array($relations) || !array_key_exists($group, $relations)) {
            return ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'reason' => 'RELATED_PROJECTION_UNAVAILABLE', 'items' => []];
        }
        return $this->itemsStatus(is_array($relations[$group]) ? $relations[$group] : []);
    }

    /** @param array<int|string,mixed> $items */
    private function itemsStatus(array $items, string $ownerStatus = 'AVAILABLE'): array
    {
        if ($ownerStatus !== 'AVAILABLE' && $ownerStatus !== 'NOT_APPLICABLE') {
            return ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'reason' => 'READ_OWNER_UNAVAILABLE', 'items' => []];
        }
        return ['status' => $items === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'items' => array_values($items)];
    }

    /** @param array<string,mixed> $packet */
    private function applyCanonicalPath(array &$packet, string $path): void
    {
        if (is_array($packet['identity'] ?? null)) $packet['identity']['url'] = $path;
        if (is_array($packet['seo_projection'] ?? null)) {
            foreach (['canonical', 'sitemap', 'breadcrumb', 'card', 'search', 'internal_link'] as $key) $packet['seo_projection'][$key] = $path;
            if (is_array($packet['seo_projection']['open_graph'] ?? null)) $packet['seo_projection']['open_graph']['url'] = $path;
            if (is_array($packet['seo_projection']['json_ld'] ?? null)) {
                foreach (['url', '@id', 'mainEntityOfPage'] as $key) if (array_key_exists($key, $packet['seo_projection']['json_ld'])) $packet['seo_projection']['json_ld'][$key] = $path;
            }
        }
        $packet['public_identity_route'] = ['path' => $path, 'source' => 'persisted_public_identity'];
    }
}
