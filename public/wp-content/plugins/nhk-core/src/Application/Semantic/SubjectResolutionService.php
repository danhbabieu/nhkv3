<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Shared\Uuid\UuidCodec;

/** Canonical subject resolver adapter. Ambiguity and absence remain explicit. */
final class SubjectResolutionService
{
    /** @param callable(string):array $resolver */
    public function __construct(private $resolver) {}

    /** @param list<string> $hints @return array<string,mixed> */
    public function resolve(array $hints): array
    {
        $values = $this->sourceValues($hints);
        $uuids = array_values(array_filter($values, static fn (string $value): bool => UuidCodec::isValid($value)));
        return $this->resolveSources([
            'canonical_uuid' => $uuids,
            'subject_hints' => array_values(array_diff($values, $uuids)),
        ]);
    }

    /**
     * Resolve typed Capture sources in one deterministic precedence policy.
     *
     * @param array<string,mixed> $sources
     * @return array<string,mixed>
     */
    public function resolveSources(array $sources): array
    {
        $uuid = $this->sourceValues($sources['canonical_uuid'] ?? []);
        $stableKey = $this->sourceValues($sources['stable_key'] ?? []);
        $hints = $this->sourceValues($sources['subject_hints'] ?? []);
        $title = $this->sourceValues($sources['title_subject'] ?? $sources['topic_subject'] ?? []);
        $body = $this->sourceValues($sources['body_mentions'] ?? []);

        foreach ([
            ['values' => $uuid, 'source' => 'canonical_uuid'],
            ['values' => $stableKey, 'source' => 'stable_key'],
        ] as $bucket) {
            $result = $this->resolveBucket($bucket['values'], $bucket['source'], false);
            if ($result['resolved'] !== []) {
                $conflicts = $this->explicitConflicts($result['resolved'][0], array_merge($stableKey, $hints));
                if ($conflicts !== []) {
                    $result['conflicts'] = $conflicts;
                    $result['diagnostics'][] = 'SUBJECT_CONFLICT_REVIEW_REQUIRED';
                    return $this->finalize($result, 'conflict');
                }
                return $result;
            }
        }

        if ($hints !== []) {
            $result = $this->resolveBucket($hints, 'explicit_subject_hint', true);
            if ($result['resolved'] !== []) return $result;
            $result['diagnostics'][] = 'SUBJECT_EXPLICIT_HINT_UNRESOLVED';
            return $this->finalize($result, 'unresolved');
        }

        foreach ([
            ['values' => $title, 'source' => 'title_subject'],
            ['values' => $body, 'source' => 'body_mention'],
        ] as $bucket) {
            $result = $this->resolveBucket($bucket['values'], $bucket['source'], true);
            if ($result['resolved'] !== []) return $result;
        }

        return $this->finalize([
            'resolved' => [], 'candidates' => [], 'unresolved' => [], 'conflicts' => [],
            'diagnostics' => ['SUBJECT_NOT_FOUND'], 'primary_source' => '',
        ], 'unresolved');
    }

    /** @param mixed $values @return list<string> */
    private function sourceValues(mixed $values): array
    {
        if (is_string($values)) $values = [$values];
        return array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), (array) $values), static fn (string $value): bool => $value !== '')));
    }

    /** @param list<string> $values @return array<string,mixed> */
    private function resolveBucket(array $values, string $source, bool $preserveOrder): array
    {
        $resolved = [];
        $candidates = [];
        $unresolved = [];
        foreach ($values as $value) {
            $matches = ($this->resolver)($value);
            $matches = is_array($matches) ? array_values(array_filter($matches, 'is_array')) : [];
            foreach ($matches as &$match) {
                if (UuidCodec::isValid($value)) $match['match'] = 'uuid_exact';
                elseif (($source === 'stable_key') && (($match['stable_key'] ?? '') === $value)) $match['match'] = 'stable_key_exact';
            }
            unset($match);
            if (count($matches) > 1) $candidates[$value] = $matches;
            if ($matches === []) {
                $unresolved[] = $value;
                continue;
            }
            usort($matches, fn (array $left, array $right): int => $this->matchRank($right) <=> $this->matchRank($left));
            foreach ($matches as $match) {
                $key = (string) (($match['type'] ?? '') . ':' . ($match['id'] ?? ''));
                if (($match['type'] ?? '') === '' || ($match['id'] ?? '') === '' || isset($resolved[$key])) continue;
                $resolved[$key] = $match;
                if (!$preserveOrder) break;
            }
        }
        $ordered = array_values($resolved);
        if (!$preserveOrder) usort($ordered, fn (array $left, array $right): int => $this->primaryRank($right) <=> $this->primaryRank($left));
        return $this->finalize([
            'resolved' => $ordered, 'candidates' => $candidates, 'unresolved' => $unresolved,
            'conflicts' => [], 'diagnostics' => $candidates === [] ? [] : ['AMBIGUOUS_SUBJECT_REVIEW'],
            'primary_source' => $ordered === [] ? '' : $source,
        ], $ordered === [] ? 'unresolved' : ($candidates === [] ? 'resolved' : 'ambiguous'));
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function finalize(array $result, string $status): array
    {
        $subjects = $result['resolved'] ?? [];
        $diagnostics = array_values(array_unique(array_merge((array) ($result['diagnostics'] ?? []), ($result['unresolved'] ?? []) !== [] ? ['SUBJECT_NOT_FOUND'] : [])));
        return [
            'status' => $status,
            'primary' => $subjects[0] ?? null,
            'primary_source' => (string) ($result['primary_source'] ?? ''),
            'subjects' => $subjects,
            'resolved' => $subjects,
            'candidates' => (array) ($result['candidates'] ?? []),
            'unresolved' => array_values((array) ($result['unresolved'] ?? [])),
            'conflicts' => array_values((array) ($result['conflicts'] ?? [])),
            'compatibility_candidates' => [],
            'diagnostics' => $diagnostics,
        ];
    }

    /** @param array<string,mixed> $primary @param list<string> $values @return list<array<string,mixed>> */
    private function explicitConflicts(array $primary, array $values): array
    {
        $conflicts = [];
        foreach (array_values(array_unique($values)) as $value) {
            $matches = ($this->resolver)($value);
            $matches = is_array($matches) ? array_values(array_filter($matches, 'is_array')) : [];
            foreach ($matches as $candidate) {
                if (($candidate['id'] ?? '') === ($primary['id'] ?? '')) continue;
                if (($candidate['type'] ?? '') === ($primary['type'] ?? '')) {
                    $conflicts[] = ['kind' => 'same_type_identity', 'expected' => $primary, 'candidate' => $candidate];
                }
            }
        }
        return $conflicts;
    }

    /** @return list<array<string,mixed>> */
    private function contradictions(array $exact, array $ordered, array $candidates): array
    {
        $conflicts = [];
        $all = $ordered;
        foreach ($candidates as $matches) foreach ($matches as $match) $all[] = $match;

        foreach ($all as $candidate) {
            if (($candidate['id'] ?? '') === ($exact['id'] ?? '')) continue;
            if (($candidate['type'] ?? '') === ($exact['type'] ?? '')) {
                $conflicts[] = ['kind' => 'same_type_identity', 'expected' => $exact, 'candidate' => $candidate];
                continue;
            }

            $exactParents = (array) (($exact['compatibility'] ?? [])['parent_ids'] ?? []);
            $candidateParents = (array) (($candidate['compatibility'] ?? [])['parent_ids'] ?? []);
            if (in_array((string) ($candidate['id'] ?? ''), $exactParents, true) || in_array((string) ($exact['id'] ?? ''), $candidateParents, true)) continue;

            $exactFamily = trim((string) (($exact['compatibility'] ?? [])['family'] ?? ''));
            $candidateFamily = trim((string) (($candidate['compatibility'] ?? [])['family'] ?? ''));
            if ($exactFamily !== '' && $candidateFamily !== '' && $exactFamily !== $candidateFamily) {
                $conflicts[] = ['kind' => 'incompatible_family', 'expected' => $exact, 'candidate' => $candidate];
            }
        }

        return $conflicts;
    }

    private function primaryRank(array $subject): int
    {
        $matchRank = match ((string) ($subject['match'] ?? '')) {
            'uuid_exact' => 10000,
            'stable_key_exact' => 9000,
            'exact_variant_reference', 'exact_variant_name_reference' => 1200,
            'exact_name_or_alias' => 1000,
            default => 0,
        };
        $typeRank = match ((string) ($subject['type'] ?? '')) {
            'specimen' => 500,
            'variant' => 400,
            'model' => 300,
            'movement' => 200,
            'brand' => 100,
            default => 0,
        };
        return $matchRank + $typeRank;
    }

    private function matchRank(array $subject): int
    {
        return match ((string) ($subject['match'] ?? '')) {
            'uuid_exact' => 3,
            'stable_key_exact' => 2,
            default => 1,
        };
    }
}
