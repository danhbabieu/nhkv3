<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

/**
 * Maps an assembled dossier to the stable, reader-safe profile consumed by
 * public templates. It does not query or mutate any canonical owner.
 */
final class SemanticProfileComposer
{
    /** @var array<string,list<string>> */
    private const SECTION_ORDER = [
        'brand' => [
            'identity', 'summary', 'hierarchy', 'models', 'variants', 'movements', 'music',
            'components', 'classifications', 'specimens', 'products', 'knowledge',
            'evidence_context', 'media_gallery', 'media', 'videos', 'articles', 'navigation',
        ],
        'movement' => ['identity', 'parent_context', 'related_movements', 'technical_configuration', 'music', 'components', 'recognition', 'variants', 'knowledge', 'evidence_context', 'media_gallery', 'videos', 'articles', 'navigation'],
        'variant' => ['identity', 'parent_context', 'configuration', 'music', 'components', 'recognition', 'evidence_context', 'nearby_variants', 'media_gallery', 'videos', 'articles', 'navigation'],
    ];

    /** @return array<string,mixed> */
    public function compose(string $type, array $dossier): array
    {
        $identity = is_array($dossier['identity'] ?? null) ? $dossier['identity'] : [];
        foreach (['canonical_id', 'stable_key', 'lifecycle', 'state', 'revision'] as $key) unset($identity[$key]);

        $relations = $this->relationSections(is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : []);
        $knowledge = is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : [];
        $videos = array_values(is_array($relations['videos'] ?? null) ? $relations['videos'] : []);
        $articles = array_values(is_array($relations['articles'] ?? null) ? $relations['articles'] : []);

        return [
            'identity' => $identity,
            'hierarchy' => $this->hierarchy($type, $relations),
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
    }

    /** @return array<string,mixed> */
    private function hierarchy(string $type, array $relations): array
    {
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
            usort($items, function (array $a, array $b): int {
                return [$this->rank($a), (string) ($a['title'] ?? ''), (int) ($a['_position'] ?? 0)] <=> [$this->rank($b), (string) ($b['title'] ?? ''), (int) ($b['_position'] ?? 0)];
            });
            foreach ($items as &$item) {
                unset($item['_position'], $item['_identity'], $item['canonical_id'], $item['stable_key']);
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
