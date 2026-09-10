<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Read-only Claim reuse decision; it never creates or mutates Knowledge. */
final class ClaimReusePolicy
{
    /** @param array<string,mixed> $candidate @param list<array<string,mixed>> $claims @return array<string,mixed>|null */
    public function find(array $candidate, array $claims): ?array
    {
        $subjectId = trim((string) ($candidate['subject_id'] ?? ''));
        $scope = trim((string) ($candidate['scope'] ?? ''));
        $text = trim((string) ($candidate['text'] ?? ''));
        if ($subjectId === '' || $scope === '' || $text === '') return null;

        foreach ($claims as $claim) {
            if (!is_array($claim)) continue;
            if (trim((string) ($claim['subject_id'] ?? '')) !== $subjectId || trim((string) ($claim['scope'] ?? '')) !== $scope) continue;
            if (trim((string) ($claim['provenance'] ?? '')) === '' || (string) ($claim['evidence_status'] ?? '') !== 'SUPPORTED_WITHIN_SCOPE') continue;
            if ($this->equivalent($text, (string) ($claim['text'] ?? ''))) return $claim;
        }
        return null;
    }

    private function equivalent(string $left, string $right): bool
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);
        if ($left === '' || $right === '') return false;
        if ($left === $right) return true;

        $leftFeatures = $this->factFeatures($left);
        $rightFeatures = $this->factFeatures($right);
        if (count($leftFeatures) < 3 || count($rightFeatures) < 3) return false;
        return count(array_intersect($leftFeatures, $rightFeatures)) >= 3;
    }

    /** @return list<string> */
    private function factFeatures(string $value): array
    {
        $value = str_replace(['tiges', 'tige', 'côn'], 'côn', $value);
        $value = str_replace(['marteaux', 'marteau', 'búa'], 'búa', $value);
        $value = str_replace(['hai', 'two'], '2', $value);
        $value = str_replace(['giai điệu', 'bài nhạc', 'melody', 'melodies'], 'music', $value);
        preg_match_all('/\d+|côn|búa|music/u', $value, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
