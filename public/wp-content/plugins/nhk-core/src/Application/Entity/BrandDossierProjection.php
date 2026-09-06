<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

/**
 * Read-only bridge between the explicit Brand structural recipe and the
 * generic semantic dossier. It never changes Graph/Authority ownership.
 */
final class BrandDossierProjection
{
    /** @return array<string,mixed> */
    public function merge(array $dossier, array $aggregation): array
    {
        if (($dossier['status'] ?? '') !== 'AVAILABLE') return $dossier;
        $sections = is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : [];

        foreach ($aggregation as $group => $items) {
            if (!is_array($items) || $items === [] || !in_array((string) $group, ['models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products'], true)) continue;
            foreach ($items as $item) {
                $candidate = $this->candidate(is_array($item) ? $item : []);
                if ($candidate === null) continue;
                $sections[$group] = $this->add(is_array($sections[$group] ?? null) ? $sections[$group] : [], $candidate);
            }
        }

        foreach ($sections as &$items) {
            if (!is_array($items)) continue;
            usort($items, fn(array $a, array $b): int => [$this->rank($a), (string) ($a['title'] ?? '')] <=> [$this->rank($b), (string) ($b['title'] ?? '')]);
        }
        unset($items);

        $dossier['relation_sections'] = $sections;
        $coverage = is_array($dossier['coverage'] ?? null) ? $dossier['coverage'] : [];
        $coverage['relation_count'] = array_sum(array_map(static fn($items): int => is_array($items) ? count($items) : 0, $sections));
        $coverage['video_count'] = count(is_array($sections['videos'] ?? null) ? $sections['videos'] : []);
        $coverage['article_count'] = count(is_array($sections['articles'] ?? null) ? $sections['articles'] : []);
        $dossier['coverage'] = $coverage;
        $dossier['profile'] = (new SemanticProfileComposer())->compose('brand', $dossier);
        return $dossier;
    }

    /** @return array<string,mixed>|null */
    private function candidate(array $item): ?array
    {
        $type = trim((string) ($item['type'] ?? ''));
        $title = trim((string) ($item['name'] ?? ''));
        $url = trim((string) ($item['url'] ?? ''));
        $origin = is_array($item['origin'] ?? null) ? $item['origin'] : [];
        $predicates = array_values(array_filter(array_map('strval', is_array($origin['path'] ?? null) ? $origin['path'] : []), static fn(string $value): bool => $value !== ''));
        if ($type === '' || $title === '' || $url === '' || $predicates === []) return null;

        return [
            'type' => $type,
            'title' => $title,
            'url' => $url,
            'origin' => [
                'kind' => (string) ($origin['kind'] ?? 'DERIVED'),
                'hop_count' => (int) ($origin['hop_count'] ?? count($predicates)),
                'predicates' => $predicates,
                'via_types' => $this->viaTypes($predicates),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function add(array $items, array $candidate): array
    {
        $identity = $this->identity($candidate);
        foreach ($items as $index => $existing) {
            if (!is_array($existing) || $this->identity($existing) !== $identity) continue;
            if ($this->rank($candidate) < $this->rank($existing)) $items[$index] = $candidate;
            return array_values($items);
        }
        $items[] = $candidate;
        return array_values($items);
    }

    /** @return array{int,int} */
    private function rank(array $item): array
    {
        return [
            (($item['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1),
            (int) ($item['origin']['hop_count'] ?? 99),
        ];
    }

    private function identity(array $item): string
    {
        $url = trim((string) ($item['url'] ?? ''));
        return $url !== '' ? $url : (string) ($item['type'] ?? '') . ':' . (string) ($item['title'] ?? '');
    }

    /** @param list<string> $predicates @return list<string> */
    private function viaTypes(array $predicates): array
    {
        return match ($predicates) {
            ['model_of'] => [],
            ['model_of', 'variant_of'], ['variant_of', 'model_of'] => ['model'],
            ['model_of', 'variant_of', 'uses_movement'],
            ['model_of', 'variant_of', 'configured_with_music'] => ['model', 'variant'],
            ['model_of', 'variant_of', 'uses_movement', 'supports_music'] => ['model', 'variant', 'movement'],
            default => [],
        };
    }
}
