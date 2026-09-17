<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Read-only Claim reuse decision; it never creates or mutates Knowledge. */
final class ClaimReusePolicy
{
    /** @param callable(array<string,mixed>):list<array<string,mixed>>|null $canonicalSearch */
    public function __construct(private $canonicalSearch = null)
    {
    }

    /** @param array<string,mixed> $candidate @param list<array<string,mixed>> $claims @return array<string,mixed>|null */
    public function find(array $candidate, array $claims): ?array
    {
        $subjectId = trim((string) ($candidate['subject_id'] ?? ''));
        $scope = trim((string) ($candidate['scope'] ?? ''));
        $text = trim((string) ($candidate['text'] ?? ''));
        if ($subjectId === '' || $scope === '' || $text === '') return null;

        $canonicalClaims = [];
        if ($this->canonicalSearch !== null) {
            try {
                $canonicalClaims = ($this->canonicalSearch)($candidate);
            } catch (\Throwable) {
                $canonicalClaims = [];
            }
        }
        foreach (array_merge($claims, is_array($canonicalClaims) ? $canonicalClaims : []) as $claim) {
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
        preg_match_all('/\d+|[\p{L}]{2,}/u', $value, $matches);
        $stopWords = ['có', 'cấu', 'hình', 'là', 'một', 'được', 'và', 'thuộc', 'nhóm', 'the', 'with', 'this'];
        return array_values(array_unique(array_filter($matches[0] ?? [], static function (string $feature) use ($stopWords): bool {
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($feature) : strtolower($feature);
            return !in_array($normalized, $stopWords, true);
        })));
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
