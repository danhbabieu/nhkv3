<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

final class DomainTargetCandidateAudit
{
    /** @var array<string,string> */
    private const DOMAIN_TYPES = [
        'nhk_brand' => 'brand',
        'nhk_model' => 'model',
        'nhk_variant' => 'variant',
        'nhk_movement' => 'movement',
        'nhk_music' => 'music',
        'nhk_component' => 'component',
        'nhk_classification' => 'classification',
        'nhk_knowledge' => 'knowledge',
    ];

    /** @param list<array<string,mixed>> $records */
    public function run(array $records): array
    {
        $targets = [];
        foreach ($records as $record) {
            $type = (string) ($record['type'] ?? '');
            if (!in_array($type, array_values(self::DOMAIN_TYPES), true)) continue;
            $identity = $this->identity($record);
            if ($identity === null) continue;
            $targets[] = ['record' => $record, 'identity' => $identity];
        }

        $indexes = ['title' => [], 'slug' => []];
        foreach ($targets as $target) {
            $record = $target['record'];
            $type = (string) $record['type'];
            $title = $this->normalize((string) ($record['canonical_name'] ?? ''));
            if ($title !== '') $indexes['title'][$type][$title][] = $target;
            $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
            $slug = $this->normalize((string) ($metadata['slug'] ?? ''));
            if ($slug !== '') $indexes['slug'][$type][$slug][] = $target;
        }

        $report = ['source_count' => count($records), 'candidate_count' => 0, 'by_legacy_type' => [], 'items' => []];
        foreach ($records as $record) {
            $legacyType = (string) ($record['legacy_type'] ?? '');
            if (!isset(self::DOMAIN_TYPES[$legacyType])) continue;
            $domainType = self::DOMAIN_TYPES[$legacyType];
            $matches = [];
            $title = $this->normalize((string) ($record['post_title'] ?? ''));
            $slug = $this->normalize((string) ($record['post_name'] ?? ''));
            foreach ([['title', $title], ['slug', $slug]] as [$basis, $value]) {
                if ($value === '') continue;
                foreach ($indexes[$basis][$domainType][$value] ?? [] as $target) {
                    $key = (string) ($target['identity']['stable_key'] ?? '');
                    if ($key === '') continue;
                    $matches[$key]['target'] = $target;
                    $matches[$key]['basis'][] = $basis;
                }
            }
            $items = [];
            foreach ($matches as $match) {
                $identity = $match['target']['identity'];
                $items[] = [
                    'type' => (string) ($match['target']['record']['type'] ?? ''),
                    'stable_key' => $identity['stable_key'],
                    'canonical_uuid' => $identity['canonical_uuid'],
                    'match_basis' => array_values(array_unique($match['basis'])),
                ];
            }
            usort($items, static fn (array $left, array $right): int => strcmp($left['stable_key'], $right['stable_key']));
            $class = count($items) === 0 ? 'none' : (count($items) === 1 ? 'one' : 'ambiguous');
            $report['by_legacy_type'][$legacyType][$class] = ($report['by_legacy_type'][$legacyType][$class] ?? 0) + 1;
            $report['items'][] = [
                'legacy_id' => (string) ($record['legacy_id'] ?? ''),
                'legacy_type' => $legacyType,
                'post_name' => (string) ($record['post_name'] ?? ''),
                'post_title' => (string) ($record['post_title'] ?? ''),
                'match_class' => $class,
                'candidates' => $items,
                'review' => ['requires_explicit_mapping' => true, 'name_only_match_forbidden' => true],
            ];
            $report['candidate_count']++;
        }
        ksort($report['by_legacy_type']);
        return $report;
    }

    /** @param array<string,mixed> $record @return array{stable_key:string,canonical_uuid:string}|null */
    private function identity(array $record): ?array
    {
        $stableKey = trim((string) ($record['stable_key'] ?? ''));
        $uuid = trim((string) ($record['canonical_uuid'] ?? ''));
        return $stableKey === '' || $uuid === '' ? null : ['stable_key' => $stableKey, 'canonical_uuid' => $uuid];
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }
}
