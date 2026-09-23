<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoSubjectResolutionDecision
{
    public static function resolve(array $input, array $candidates, array $context = []): array
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || trim((string) ($candidate['id'] ?? '')) === '') continue;
            $scored[] = ['candidate' => $candidate, 'score' => round(self::score($input, $candidate, $context), 4), 'features' => self::features($input, $candidate, $context)];
        }
        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        if ($scored === []) return self::result('HARD_BLOCK', null, [], [], 0.0, [], 'CANONICAL_IDENTITY_UNRESOLVED');
        $top = $scored[0];
        $second = $scored[1]['score'] ?? 0.0;
        $conflicts = self::conflicts($scored);
        $clear = $top['score'] >= 0.45 && (($top['score'] - $second) >= 0.15 || count($scored) === 1) && $conflicts === [];
        $decision = $clear ? 'AUTO_RESOLVE' : 'HUMAN_REVIEW';
        $trace = [];
        foreach ($scored as $row) $trace[] = ['candidate_id' => (string) $row['candidate']['id'], 'score' => $row['score'], 'features' => $row['features'], 'action' => $clear && $row === $top ? 'SELECT' : 'RETAIN_AS_CANDIDATE'];
        return self::result($decision, $clear ? $top['candidate'] : null, $scored, $trace, (float) $top['score'], $conflicts, $clear ? 'CLEAR_SEMANTIC_WINNER' : 'COMPETING_CANDIDATES');
    }

    private static function result(string $decision, ?array $selected, array $scored, array $trace, float $confidence, array $conflicts, string $reason): array
    {
        return ['decision' => $decision, 'selected' => $selected, 'scored_candidates' => $scored, 'discrimination_features' => array_values(array_map(static fn (array $row): array => $row['features'], $scored)), 'confidence' => $confidence, 'conflicts' => $conflicts, 'trace' => $trace, 'reason' => $reason];
    }

    private static function score(array $input, array $candidate, array $context): float
    {
        $features = self::features($input, $candidate, $context);
        $weights = ['name' => .35, 'alias' => .25, 'parent' => .15, 'facets' => .1, 'music' => .05, 'movement' => .05, 'observations' => .05];
        return min(1.0, array_sum(array_map(static fn (string $key): float => ($features[$key] ?? 0.0) * $weights[$key], array_keys($weights))));
    }

    private static function features(array $input, array $candidate, array $context): array
    {
        $name = self::normalize($input['name'] ?? $input['subject'] ?? '');
        $candidateName = self::normalize($candidate['name'] ?? '');
        $aliases = array_map([self::class, 'normalize'], (array) ($candidate['aliases'] ?? []));
        $parent = self::normalize($input['parent'] ?? $input['model'] ?? '');
        $candidateParent = self::normalize($candidate['parent'] ?? $candidate['model'] ?? '');
        return ['name' => $name !== '' && $name === $candidateName ? 1.0 : 0.0, 'alias' => $name !== '' && in_array($name, $aliases, true) ? 1.0 : 0.0, 'parent' => $parent !== '' && $parent === $candidateParent ? 1.0 : 0.0, 'facets' => self::overlap($input['configuration_facets'] ?? [], $candidate['configuration_facets'] ?? []), 'music' => self::overlap($input['music'] ?? [], $candidate['music'] ?? []), 'movement' => self::overlap($input['movement'] ?? [], $candidate['movement'] ?? []), 'observations' => min(1.0, self::overlap($input['observations'] ?? [], $candidate['observations'] ?? []) + self::overlap($context['observations'] ?? [], $candidate['observations'] ?? []))];
    }

    private static function conflicts(array $scored): array
    {
        $conflicts = [];
        foreach ($scored as $index => $left) foreach (array_slice($scored, $index + 1) as $right) {
            $leftFamily = trim((string) ($left['candidate']['family'] ?? ''));
            $rightFamily = trim((string) ($right['candidate']['family'] ?? ''));
            if ($leftFamily !== '' && $rightFamily !== '' && $leftFamily !== $rightFamily && self::normalize($left['candidate']['name'] ?? '') === self::normalize($right['candidate']['name'] ?? '')) $conflicts[] = ['kind' => 'incompatible_family', 'left' => $left['candidate'], 'right' => $right['candidate']];
        }
        return $conflicts;
    }

    private static function overlap(mixed $left, mixed $right): float
    {
        $left = array_values(array_filter(array_map([self::class, 'normalize'], is_array($left) ? $left : [$left])));
        $right = array_values(array_filter(array_map([self::class, 'normalize'], is_array($right) ? $right : [$right])));
        if ($left === [] || $right === []) return 0.0;
        return count(array_intersect($left, $right)) / count(array_unique($left));
    }

    private static function normalize(mixed $value): string { return strtolower(trim((string) $value)); }
}
