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
        $resolved = [];
        $candidates = [];
        $unresolved = [];
        $hints = array_values(array_unique(array_filter(array_map('trim', $hints), static fn (string $hint): bool => $hint !== '')));
        $explicitUuid = array_values(array_filter($hints, static fn (string $hint): bool => UuidCodec::isValid($hint)));
        // An exact UUID is authoritative for identity, but the remaining
        // explicit hints are still checked for contradiction. Previously they
        // were discarded, allowing a stale UUID plus a conflicting name to
        // pass as a successful resolution.
        foreach ($hints as $hint) {
            $matches = ($this->resolver)($hint);
            $matches = is_array($matches) ? array_values(array_filter($matches, 'is_array')) : [];
            if (count($matches) === 1) {
                $item = $matches[0];
                if (in_array($hint, $explicitUuid, true)) $item['match'] = 'uuid_exact';
                $key = (string) (($item['type'] ?? '') . ':' . ($item['id'] ?? ''));
                if (($item['type'] ?? '') !== '' && ($item['id'] ?? '') !== '') {
                    if (!isset($resolved[$key]) || $this->matchRank($item) > $this->matchRank($resolved[$key])) $resolved[$key] = $item;
                }
            } elseif (count($matches) > 1) {
                $candidates[$hint] = $matches;
            } else {
                $unresolved[] = $hint;
            }
        }
        $ordered = array_values($resolved);
        usort($ordered, function (array $left, array $right): int {
            $score = $this->primaryRank($right) <=> $this->primaryRank($left);
            return $score !== 0 ? $score : strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });
        $exact = null;
        foreach ($ordered as $subject) {
            if (($subject['match'] ?? '') === 'uuid_exact') {
                $exact = $subject;
                break;
            }
        }
        $conflicts = $exact !== null ? $this->contradictions($exact, $ordered, $candidates) : [];
        $status = $conflicts !== []
            ? 'conflict'
            : ($candidates !== [] ? 'ambiguous' : ($ordered !== [] ? 'resolved' : 'unresolved'));
        $diagnostics = [];
        if ($conflicts !== []) $diagnostics[] = 'SUBJECT_CONFLICT_REVIEW_REQUIRED';
        if ($candidates !== []) $diagnostics[] = 'AMBIGUOUS_SUBJECT_REVIEW';
        if ($unresolved !== []) $diagnostics[] = 'SUBJECT_NOT_FOUND';

        // Preserve the exact UUID as the only selected subject. Other exact
        // hints are diagnostic candidates until a governed correction is
        // explicitly approved.
        $selected = $exact !== null ? [$exact] : $ordered;
        return [
            'status' => $status,
            'primary' => $exact ?? ($ordered[0] ?? null),
            'subjects' => $selected,
            'resolved' => $selected,
            'candidates' => $candidates,
            'unresolved' => $unresolved,
            'conflicts' => $conflicts,
            'compatibility_candidates' => $exact !== null ? $ordered : [],
            'diagnostics' => array_values(array_unique($diagnostics)),
        ];
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
