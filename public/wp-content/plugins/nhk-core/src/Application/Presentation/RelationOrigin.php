<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/**
 * Reader-safe provenance for a related presentation item.
 *
 * This class only interprets path metadata already returned by the Graph
 * projector. It never creates, persists or infers a semantic relation.
 */
final class RelationOrigin
{
    /** @param array<string,mixed> $origin @return array{kind:string,hop_count:int,predicates:list<string>,via_types:list<string>,path_kind:string} */
    public static function normalize(array $origin): array
    {
        $kind = strtoupper(trim((string) ($origin['kind'] ?? 'DERIVED')));
        $kind = $kind === 'DIRECT' ? 'DIRECT' : 'DERIVED';
        $predicates = self::stringList($origin['predicates'] ?? []);
        $viaTypes = self::stringList($origin['via_types'] ?? []);

        return [
            'kind' => $kind,
            'hop_count' => max(0, (int) ($origin['hop_count'] ?? ($kind === 'DIRECT' ? 1 : 0))),
            'predicates' => $predicates,
            'via_types' => $viaTypes,
            'path_kind' => $kind === 'DIRECT' ? 'DIRECT' : self::pathKind($predicates, $viaTypes),
        ];
    }

    /** @param array<string,mixed> $item */
    public static function rank(array $item): array
    {
        $origin = self::normalize(is_array($item['origin'] ?? null) ? $item['origin'] : []);
        return [$origin['kind'] === 'DIRECT' ? 0 : 1, $origin['hop_count']];
    }

    /** @param list<string> $predicates @param list<string> $viaTypes */
    private static function pathKind(array $predicates, array $viaTypes): string
    {
        $haystack = array_map('strtolower', [...$predicates, ...$viaTypes]);
        foreach (['subtype_of' => 'DERIVED_VIA_SUBTYPE', 'article' => 'DERIVED_VIA_ARTICLE', 'wp_post' => 'DERIVED_VIA_ARTICLE', 'model' => 'DERIVED_VIA_MODEL', 'variant' => 'DERIVED_VIA_VARIANT', 'specimen' => 'DERIVED_VIA_SPECIMEN', 'product' => 'DERIVED_VIA_PRODUCT'] as $needle => $label) {
            if (in_array($needle, $haystack, true)) return $label;
        }
        return in_array('object', $haystack, true) ? 'DERIVED_VIA_OBJECT' : 'DERIVED';
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') $result[] = $item;
        }
        return array_values(array_unique($result));
    }
}
