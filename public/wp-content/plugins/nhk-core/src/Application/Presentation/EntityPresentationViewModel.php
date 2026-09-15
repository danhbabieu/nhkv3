<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/**
 * Shared, reader-safe presentation packet for entity, article and media
 * dossier renderers. It composes existing projection data and never queries or
 * mutates a canonical owner.
 */
final class EntityPresentationViewModel
{
    /** @var list<string> */
    private const GROUPS = ['brands', 'models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'articles', 'media', 'videos'];

    /** @return array<string,mixed> */
    public static function fromDossier(string $type, array $dossier): array
    {
        $profile = is_array($dossier['profile'] ?? null) ? $dossier['profile'] : [];
        $identity = self::safeIdentity(is_array($profile['identity'] ?? null) ? $profile['identity'] : (is_array($dossier['identity'] ?? null) ? $dossier['identity'] : []));
        $payload = is_array(($dossier['identity']['payload'] ?? null)) ? $dossier['identity']['payload'] : [];
        $relations = is_array($profile['relation_sections'] ?? null) ? $profile['relation_sections'] : (is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : []);
        $relations = self::normalizeRelations($relations);

        $knowledge = is_array($profile['knowledge'] ?? null) ? $profile['knowledge'] : (is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : []);
        $media = is_array($profile['media_gallery'] ?? null) ? $profile['media_gallery'] : (is_array($dossier['media_gallery'] ?? null) ? $dossier['media_gallery'] : []);
        $primaryMedia = is_array($profile['primary_media'] ?? null) ? $profile['primary_media'] : (is_array($dossier['primary_media'] ?? null) ? $dossier['primary_media'] : []);
        $media = self::safeItems($media);
        $sections = [];
        foreach (self::GROUPS as $group) {
            $items = $group === 'media' && $media !== [] ? $media : ($relations[$group] ?? []);
            $ownerStatus = $group === 'articles' || $group === 'videos' || $group === 'media'
                ? (string) (($dossier['availability'][$group] ?? $dossier['availability'][rtrim($group, 's')] ?? 'AVAILABLE'))
                : 'AVAILABLE';
            $sections[$group] = SectionStatus::forItems($items, $ownerStatus);
        }
        $knowledgeStatus = strtoupper((string) ($knowledge['status'] ?? $dossier['availability']['knowledge'] ?? 'AVAILABLE'));
        $sections['knowledge'] = SectionStatus::forItems(self::knowledgeItems($knowledge), $knowledgeStatus === 'NOT_APPLICABLE' ? 'EMPTY' : $knowledgeStatus);

        $hierarchy = self::hierarchy($profile, $dossier);
        $breadcrumbs = self::safeItems(is_array($profile['breadcrumbs'] ?? null) ? $profile['breadcrumbs'] : (is_array($dossier['breadcrumbs'] ?? null) ? $dossier['breadcrumbs'] : []));
        if ($breadcrumbs === []) {
            if ($hierarchy['parent'] !== []) $breadcrumbs[] = $hierarchy['parent'];
            if (($identity['name'] ?? '') !== '') $breadcrumbs[] = ['name' => (string) $identity['name'], 'url' => ''];
        }
        $counts = is_array($profile['coverage'] ?? null) ? $profile['coverage'] : (is_array($dossier['coverage'] ?? null) ? $dossier['coverage'] : []);
        foreach (['models', 'variants', 'movements', 'music', 'components', 'specimens', 'products', 'articles', 'media', 'videos'] as $group) {
            $counts[$group === 'music' ? 'melody_count' : rtrim($group, 's') . '_count'] = $sections[$group]['count'];
        }

        $readiness = is_array($profile['presentation_readiness'] ?? null) ? $profile['presentation_readiness'] : (is_array($dossier['presentation_readiness'] ?? null) ? $dossier['presentation_readiness'] : self::deriveReadiness($dossier, $identity, $payload, $primaryMedia));
        return [
            'type' => $type,
            'identity' => $identity,
            'route' => (string) ($identity['url'] ?? ''),
            'title' => (string) ($identity['title'] ?? $identity['name'] ?? ''),
            'subtitle' => self::text($payload['subtitle'] ?? $payload['label'] ?? ''),
            'summary' => self::text($payload['summary'] ?? $payload['description'] ?? $identity['excerpt'] ?? ''),
            'description' => self::text($payload['description'] ?? $payload['summary'] ?? ''),
            'hero_media' => self::safeMedia($primaryMedia),
            'breadcrumbs' => $breadcrumbs,
            'parent' => $hierarchy['parent'],
            'children' => $hierarchy['children'],
            'siblings' => $hierarchy['siblings'],
            'knowledge' => self::safeKnowledge($knowledge),
            'articles' => $sections['articles']['items'],
            'media' => $sections['media']['items'],
            'videos' => $sections['videos']['items'],
            'models' => $sections['models']['items'],
            'variants' => $sections['variants']['items'],
            'movements' => $sections['movements']['items'],
            'melodies' => $sections['music']['items'],
            'parts' => $sections['components']['items'],
            'specimens' => $sections['specimens']['items'],
            'products' => $sections['products']['items'],
            'derived_brands' => self::safeItems(is_array($dossier['clock_type_derived_brands']['items'] ?? null) ? $dossier['clock_type_derived_brands']['items'] : $sections['brands']['items']),
            'related_entities' => self::relatedEntities($relations),
            'counts' => $counts,
            'section_status' => $sections,
            'presentation_readiness' => $readiness,
            'relation_origin' => self::strongestOrigin($relations),
            'relation_depth' => self::relationDepth($relations),
            'timestamps' => self::timestamps($dossier),
            'warnings' => array_values(array_unique(array_map('strval', is_array($dossier['warnings'] ?? null) ? $dossier['warnings'] : []))),
            'profile' => is_array($profile['section_order'] ?? null) ? $profile : [],
        ];
    }

    /** @return array<string,mixed> */
    private static function safeIdentity(array $identity): array
    {
        foreach (['canonical_id', 'stable_key', 'lifecycle', 'state', 'revision', 'payload'] as $key) unset($identity[$key]);
        return $identity;
    }

    /** @param array<string,mixed> $relations @return array<string,list<array<string,mixed>>> */
    private static function normalizeRelations(array $relations): array
    {
        $result = [];
        foreach ($relations as $group => $items) {
            if (!is_array($items)) continue;
            $unique = [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $identity = trim((string) ($item['canonical_id'] ?? $item['url'] ?? $item['title'] ?? ''));
                if ($identity === '') continue;
                $item['relation_origin'] = RelationOrigin::normalize(is_array($item['origin'] ?? null) ? $item['origin'] : []);
                $item['relation_depth'] = $item['relation_origin']['hop_count'];
                if (!isset($unique[$identity]) || RelationOrigin::rank($item) < RelationOrigin::rank($unique[$identity])) $unique[$identity] = $item;
            }
            foreach ($unique as &$item) {
                unset($item['canonical_id'], $item['stable_key'], $item['_created_at'], $item['_updated_at'], $item['_published_at'], $item['_stable_key']);
            }
            unset($item);
            $result[(string) $group] = array_values($unique);
        }
        return $result;
    }

    /** @return array{parent:array<string,mixed>,children:list<array<string,mixed>>,siblings:list<array<string,mixed>>} */
    private static function hierarchy(array $profile, array $dossier): array
    {
        $source = is_array($profile['hierarchy'] ?? null) ? $profile['hierarchy'] : (is_array($dossier['clock_type_hierarchy'] ?? null) ? $dossier['clock_type_hierarchy'] : []);
        return ['parent' => self::safeHierarchy(is_array($source['parent'] ?? null) ? $source['parent'] : []), 'children' => self::safeItems(is_array($source['children'] ?? null) ? $source['children'] : []), 'siblings' => self::safeItems(is_array($source['siblings'] ?? null) ? $source['siblings'] : [])];
    }

    /** @return array<string,mixed> */
    private static function safeHierarchy(array $item): array
    {
        foreach (['canonical_id', 'stable_key', 'revision', 'state'] as $key) unset($item[$key]);
        return $item;
    }

    /** @param list<array<string,mixed>> $relations @return list<array<string,mixed>> */
    private static function relatedEntities(array $relations): array
    {
        $items = [];
        foreach ($relations as $group => $groupItems) if ($group !== 'media' && $group !== 'videos' && $group !== 'articles') foreach ($groupItems as $item) $items[] = $item;
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private static function knowledgeItems(array $knowledge): array
    {
        $items = [];
        foreach ((array) ($knowledge['facets'] ?? []) as $facet) {
            if (is_array($facet) && array_is_list($facet)) foreach ($facet as $item) if (is_array($item)) $items[] = $item;
            elseif (is_array($facet)) $items[] = $facet;
        }
        return $items;
    }

    private static function safeKnowledge(array $knowledge): array
    {
        unset($knowledge['canonical_id'], $knowledge['stable_key'], $knowledge['revision'], $knowledge['metadata']);
        $knowledge['facets'] = is_array($knowledge['facets'] ?? null) ? $knowledge['facets'] : [];
        return $knowledge;
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private static function safeItems(array $items): array
    {
        return array_values(array_filter(array_map(static function (mixed $item): ?array {
            if (!is_array($item)) return null;
            foreach (['canonical_id', 'stable_key', 'revision', 'state', 'lifecycle'] as $key) unset($item[$key]);
            return $item;
        }, $items)));
    }

    /** @return array<string,mixed>|null */
    private static function safeMedia(array $media): ?array
    {
        if (trim((string) ($media['url'] ?? '')) === '') return null;
        return ['url' => (string) $media['url'], 'alt' => (string) ($media['alt'] ?? ''), 'caption' => (string) ($media['caption'] ?? ''), 'width' => isset($media['width']) ? (int) $media['width'] : null, 'height' => isset($media['height']) ? (int) $media['height'] : null];
    }

    private static function text(mixed $value): string { return is_scalar($value) ? trim((string) $value) : ''; }

    /** @return array{status:string,reasons:list<string>} */
    private static function deriveReadiness(array $dossier, array $identity, array $payload, array $primaryMedia): array
    {
        $readiness = PresentationReadiness::evaluate(
            ['active' => ($dossier['status'] ?? '') === 'AVAILABLE'],
            [
                'route' => (string) ($identity['url'] ?? ''),
                'content' => [
                    'name' => (string) ($identity['name'] ?? $identity['title'] ?? ''),
                    'description' => (string) ($payload['description'] ?? $payload['summary'] ?? ''),
                    'representative_media' => $primaryMedia !== [],
                ],
                'public_eligible' => ($dossier['status'] ?? '') === 'AVAILABLE',
            ],
        );
        return ['status' => $readiness->presentationStatus(), 'reasons' => $readiness->reasons()];
    }

    /** @return array<string,mixed> */
    private static function strongestOrigin(array $relations): array
    {
        $best = null;
        foreach ($relations as $items) foreach ($items as $item) if ($best === null || RelationOrigin::rank($item) < RelationOrigin::rank($best)) $best = $item;
        return $best === null ? RelationOrigin::normalize([]) : $best['relation_origin'];
    }

    private static function relationDepth(array $relations): int
    {
        $depth = 0;
        foreach ($relations as $items) foreach ($items as $item) $depth = max($depth, (int) ($item['relation_depth'] ?? 0));
        return $depth;
    }

    /** @return array<string,string> */
    private static function timestamps(array $dossier): array
    {
        $timestamps = is_array($dossier['timestamps'] ?? null) ? $dossier['timestamps'] : [];
        foreach (['published_at', 'created_at', 'updated_at'] as $key) if (isset($dossier[$key]) && is_string($dossier[$key])) $timestamps[$key] = $dossier[$key];
        return array_intersect_key($timestamps, array_flip(['published_at', 'created_at', 'updated_at']));
    }
}
