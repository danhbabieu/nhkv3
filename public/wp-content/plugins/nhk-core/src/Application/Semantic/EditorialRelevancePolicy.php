<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Read-only relevance gate for shared editorial Claim selection. */
final class EditorialRelevancePolicy
{
    /** @param array<string,mixed> $candidate @param array<string,mixed> $subject */
    public function evaluate(array $candidate, string $topic, array $subject): array
    {
        $explicit = strtoupper(trim((string) ($candidate['topic_relevance'] ?? $candidate['relevance_status'] ?? '')));
        if (in_array($explicit, ['IRRELEVANT', 'INAPPLICABLE', 'REJECTED'], true)) {
            return ['eligible' => false, 'reason' => 'TOPIC_IRRELEVANT', 'basis' => 'EXPLICIT_RELEVANCE_DECISION'];
        }

        foreach (['relevance', 'topic_relevance_score', 'relevance_score'] as $field) {
            if (!is_numeric($candidate[$field] ?? null)) continue;
            if ((float) $candidate[$field] < 0.25) return ['eligible' => false, 'reason' => 'TOPIC_IRRELEVANT', 'basis' => $field];
            return ['eligible' => true, 'reason' => null, 'basis' => $field];
        }

        // A graph path establishes bounded discoverability, not editorial
        // applicability. Neighborhood context needs a topic signal unless a
        // trusted upstream relevance decision already accepted it.
        if (strtolower(trim((string) ($candidate['retrieval_origin'] ?? ''))) === 'neighborhood'
            && $this->overlap($topic, (string) ($candidate['text'] ?? $candidate['claim_text'] ?? '')) === 0) {
            return ['eligible' => false, 'reason' => 'TOPIC_IRRELEVANT', 'basis' => 'NEIGHBORHOOD_WITHOUT_TOPIC_OVERLAP'];
        }

        return ['eligible' => true, 'reason' => null, 'basis' => 'SUBJECT_OR_TOPIC_COMPATIBLE'];
    }

    private function overlap(string $topic, string $claim): int
    {
        $tokens = static function (string $value): array {
            $lower = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
            return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [], static fn (string $token): bool => mb_strlen($token) >= 3)));
        };
        return count(array_intersect($tokens($topic), $tokens($claim)));
    }
}
