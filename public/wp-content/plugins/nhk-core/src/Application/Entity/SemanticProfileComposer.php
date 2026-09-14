<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Application\Presentation\LatestFirstOrder;

/**
 * Maps an assembled dossier to the stable, reader-safe profile consumed by
 * public templates. It does not query or mutate any canonical owner.
 */
final class SemanticProfileComposer
{
    /** @var array<string,int> */
    public const PREVIEW_LIMITS = [
        'articles' => 5,
        'media' => 6,
        'videos' => 4,
        'knowledge' => 5,
        'specimens' => 5,
        'models' => 6,
        'variants' => 6,
    ];

    /** @var array<string,list<string>> */
    private const SECTION_ORDER = [
        'brand' => [
            'identity', 'summary', 'hierarchy', 'models', 'variants', 'movements', 'music',
            'components', 'classifications', 'specimens', 'products', 'knowledge',
            'evidence_context', 'media_gallery', 'media', 'videos', 'articles', 'navigation',
        ],
        'model' => ['identity', 'parent_context', 'summary', 'variants', 'movements', 'music', 'components', 'knowledge', 'media_gallery', 'videos', 'articles', 'navigation'],
        'movement' => ['identity', 'parent_context', 'related_movements', 'technical_configuration', 'music', 'components', 'recognition', 'variants', 'knowledge', 'evidence_context', 'media_gallery', 'videos', 'articles', 'navigation'],
        'variant' => ['identity', 'parent_context', 'configuration', 'music', 'components', 'recognition', 'evidence_context', 'nearby_variants', 'media_gallery', 'videos', 'articles', 'navigation'],
        'music' => ['identity', 'summary', 'historical_context', 'movements', 'models', 'variants', 'brands', 'knowledge', 'media_gallery', 'videos', 'articles', 'navigation'],
        'component' => ['identity', 'summary', 'technical_configuration', 'movements', 'models', 'variants', 'classifications', 'knowledge', 'media_gallery', 'videos', 'articles', 'navigation'],
        'clock_type' => ['identity', 'knowledge', 'classifications', 'brands', 'models', 'variants', 'specimens', 'products', 'media_gallery', 'videos', 'articles', 'navigation'],
        'classification' => ['identity', 'summary', 'related_entities', 'knowledge', 'media_gallery', 'videos', 'articles', 'navigation'],
        'specimen' => ['identity', 'parent_context', 'measurements', 'provenance', 'knowledge', 'articles', 'media_gallery', 'videos', 'related_entities', 'navigation'],
        'product' => ['identity', 'object_context', 'brand_context', 'model_context', 'variant_context', 'media_gallery', 'videos', 'articles', 'knowledge', 'navigation'],
    ];

    /** @return array<string,int> */
    public static function previewLimits(): array { return self::PREVIEW_LIMITS; }

    /** @return array<string,mixed> */
    public function compose(string $type, array $dossier): array
    {
        $identity = is_array($dossier['identity'] ?? null) ? $dossier['identity'] : [];
        foreach (['canonical_id', 'stable_key', 'lifecycle', 'state', 'revision'] as $key) unset($identity[$key]);

        $relations = $this->relationSections(is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : []);
        $knowledge = is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : [];
        $videos = array_values(is_array($relations['videos'] ?? null) ? $relations['videos'] : []);
        $articles = array_values(is_array($relations['articles'] ?? null) ? $relations['articles'] : []);

        $result = [
            'identity' => $identity,
            'hierarchy' => $this->hierarchy($type, $relations, is_array($dossier['clock_type_hierarchy'] ?? null) ? $dossier['clock_type_hierarchy'] : []),
            'relation_sections' => $relations,
            'knowledge' => $knowledge,
            'evidence_context' => $this->evidenceContext($knowledge),
            'primary_media' => is_array($dossier['primary_media'] ?? null) ? $dossier['primary_media'] : [],
            'media_gallery' => array_values(is_array($dossier['media_gallery'] ?? null) ? $dossier['media_gallery'] : []),
            'videos' => $videos,
            'articles' => $articles,
            'navigation' => is_array($dossier['navigation'] ?? null) ? $dossier['navigation'] : [],
            'coverage' => is_array($dossier['coverage'] ?? null) ? $dossier['coverage'] : [],
            'availability' => is_array($dossier['availability'] ?? null) ? $dossier['availability'] : [],
            'warnings' => array_values(is_array($dossier['warnings'] ?? null) ? $dossier['warnings'] : []),
            'seo_projection' => is_array($dossier['seo_projection'] ?? null) ? $dossier['seo_projection'] : null,
            'section_order' => self::SECTION_ORDER[$type] ?? ['identity', 'relation_sections', 'knowledge', 'media_gallery', 'videos', 'articles', 'navigation'],
            'relation_order' => $this->relationOrder($type),
        ];
        if ($type === 'clock_type') $result['media_context'] = is_array($dossier['media_context'] ?? null) ? $this->publicMediaContext($dossier['media_context']) : [];
        return $result;
    }

    /** @return array<string,mixed> */
    private function hierarchy(string $type, array $relations, array $clockTypeHierarchy = []): array
    {
        if ($type === 'clock_type') {
            return [
                'parent' => $this->publicHierarchyItem($clockTypeHierarchy['parent'] ?? []),
                'children' => array_values(array_filter(array_map(fn (mixed $item): array => $this->publicHierarchyItem(is_array($item) ? $item : []), is_array($clockTypeHierarchy['children'] ?? null) ? $clockTypeHierarchy['children'] : []))),
                'status' => (string) ($clockTypeHierarchy['status'] ?? 'UNAVAILABLE'),
            ];
        }
        $keys = match ($type) {
            'brand' => ['models', 'movements', 'variants'],
            'movement' => ['models', 'variants'],
            'variant' => ['models', 'movements'],
            default => [],
        };
        $result = [];
        foreach ($keys as $key) if (isset($relations[$key]) && is_array($relations[$key]) && $relations[$key] !== []) $result[$key] = $relations[$key];
        return $result;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function publicHierarchyItem(array $item): array
    {
        if ($item === []) return [];
        $result = ['name' => (string) ($item['name'] ?? ''), 'kind' => (string) ($item['kind'] ?? '')];
        $url = trim((string) ($item['url'] ?? ''));
        if ($url !== '' && str_starts_with($url, '/')) $result['url'] = $url;
        return $result;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function publicMediaContext(array $context): array
    {
        $result = [];
        foreach (['direct', 'derived'] as $kind) {
            $bucket = is_array($context[$kind] ?? null) ? $context[$kind] : [];
            $result[$kind] = ['status' => (string) ($bucket['status'] ?? 'UNAVAILABLE'), 'items' => is_array($bucket['items'] ?? null) ? array_values($bucket['items']) : []];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function evidenceContext(array $knowledge): array
    {
        return [
            'status' => (string) ($knowledge['status'] ?? 'UNAVAILABLE'),
            'coverage' => is_array($knowledge['coverage'] ?? null) ? $knowledge['coverage'] : [],
            'warnings' => array_values(is_array($knowledge['warnings'] ?? null) ? $knowledge['warnings'] : []),
        ];
    }

    /** @return list<string> */
    private function relationOrder(string $type): array
    {
        return match ($type) {
            'brand' => ['models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'media', 'videos', 'articles'],
            'movement' => ['models', 'movements', 'music', 'components', 'variants', 'media', 'videos', 'articles'],
            'variant' => ['models', 'movements', 'music', 'components', 'variants', 'media', 'videos', 'articles'],
            'model' => ['variants', 'movements', 'music', 'components', 'specimens', 'products', 'media', 'videos', 'articles'],
            'music' => ['movements', 'models', 'variants', 'brands', 'media', 'videos', 'articles'],
            'component' => ['movements', 'models', 'variants', 'classifications', 'media', 'videos', 'articles'],
            'classification' => ['brands', 'models', 'variants', 'movements', 'specimens', 'products', 'media', 'videos', 'articles'],
            'specimen' => ['brands', 'models', 'variants', 'movements', 'music', 'components', 'classifications', 'products', 'media', 'videos', 'articles'],
            'product' => ['brands', 'models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'media', 'videos', 'articles'],
            default => ['brands', 'models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'media', 'videos', 'articles'],
        };
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function relationSections(array $relations): array
    {
        foreach ($relations as $group => &$items) {
            if (!is_array($items)) {
                unset($relations[$group]);
                continue;
            }
            $unique = [];
            foreach ($items as $position => $item) {
                if (!is_array($item)) continue;
                $identity = (string) ($item['canonical_id'] ?? $item['url'] ?? $item['title'] ?? '');
                if ($identity === '') continue;
                $item['_position'] = $position;
                $item['_identity'] = $identity;
                if (!isset($unique[$identity]) || $this->rank($item) < $this->rank($unique[$identity])) $unique[$identity] = $item;
            }
            $items = array_values($unique);
            $buckets = [];
            foreach ($items as $item) $buckets[implode(':', $this->rank($item))][] = $item;
            uksort($buckets, static fn (string $a, string $b): int => $a <=> $b);
            $items = [];
            foreach ($buckets as $bucket) {
                $bucket = LatestFirstOrder::sort(
                    $bucket,
                    static fn (array $item): ?string => $item['_published_at'] ?? null,
                    static fn (array $item): ?string => $item['_created_at'] ?? null,
                    static fn (array $item): string => (string) ($item['_stable_key'] ?? $item['canonical_id'] ?? $item['url'] ?? $item['title'] ?? ''),
                    null,
                    static fn (array $item): ?string => $item['_updated_at'] ?? null,
                );
                foreach ($bucket as $item) $items[] = $item;
            }
            foreach ($items as &$item) {
                unset($item['_position'], $item['_identity'], $item['canonical_id'], $item['stable_key'], $item['_published_at'], $item['_created_at'], $item['_updated_at'], $item['_stable_key']);
            }
            unset($item);
        }
        unset($items);
        return $relations;
    }

    /** @param array<string,mixed> $item */
    private function rank(array $item): array
    {
        return [
            (($item['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1),
            (int) ($item['origin']['hop_count'] ?? 99),
        ];
    }
}
