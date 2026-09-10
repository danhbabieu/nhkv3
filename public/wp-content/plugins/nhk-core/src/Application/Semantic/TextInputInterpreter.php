<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Deterministic candidate extractor. It never promotes input to canonical truth. */
final class TextInputInterpreter
{
    /** @param list<array<string,mixed>> $assets @param list<string> $subjectHints @return array<string,mixed> */
    public function interpret(string $text, array $assets = [], array $subjectHints = []): array
    {
        $text = trim($text);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?。！？])\s+/u', $text) ?: []), static fn (string $item): bool => $item !== ''));
        if ($sentences === [] && $text !== '') $sentences = [$text];
        $mentions = [];
        foreach ($sentences as $sentence) {
            if (preg_match_all('/(?:[A-ZĐ][\p{L}\d]*(?:[\s-]+[A-ZĐ0-9][\p{L}\d]*){0,4})/u', $sentence, $matches)) {
                foreach ($matches[0] as $mention) {
                    $mention = trim((string) $mention, " \t\n\r.,;:()[]{}\"'");
                    if ($mention !== '' && !in_array($mention, $mentions, true)) $mentions[] = $mention;
                }
            }
        }
        $claims = [];
        foreach ($sentences as $sentence) $claims[] = $this->userCandidate($sentence);
        $mediaObservations = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) continue;
            $observation = trim((string) ($asset['observation'] ?? $asset['observed_text'] ?? ''));
            if ($observation === '') continue;
            $mediaObservations[] = ['text' => $observation, 'provenance' => 'OBSERVED_FROM_MEDIA', 'media_id' => (string) ($asset['media_id'] ?? '')];
        }
        return [
            'primary_subject_hints' => array_values(array_unique(array_map('strval', $subjectHints))),
            'secondary_subject_hints' => [],
            'entity_mentions' => $mentions,
            'user_claim_candidates' => $claims,
            'media_observations' => $mediaObservations,
            'relation_hints' => [],
            'article_intent' => $text,
            'uncertainty' => $text === '' ? ['EMPTY_INPUT'] : [],
            'asset_count' => count($assets),
        ];
    }

    /** @return array<string,mixed> */
    private function userCandidate(string $sentence): array
    {
        $sentence = trim($sentence, " \t\n\r-•*");
        $lower = function_exists('mb_strtolower') ? mb_strtolower($sentence) : strtolower($sentence);
        $configuration = str_contains($lower, 'côn') || str_contains($lower, 'tiges') || str_contains($lower, 'búa') || str_contains($lower, 'marteaux') || str_contains($lower, 'cấu hình');
        $music = str_contains($lower, 'bài nhạc') || str_contains($lower, 'giai điệu') || str_contains($lower, 'chơi 2 bài');
        $subjective = str_contains($lower, 'nguyên bản') || str_contains($lower, 'âm thanh') || str_contains($lower, 'đánh giá') || str_contains($lower, 'video');
        $recognition = str_contains($lower, 'yêu thích') || str_contains($lower, 'nữ hoàng') || str_contains($lower, 'cộng đồng') || str_contains($lower, 'nhận xét');
        return [
            'text' => $sentence,
            'candidate_kind' => 'user_statement',
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            'scope' => $subjective ? 'specimen_observation' : 'variant',
            'facet' => $configuration ? 'configuration' : ($music ? 'music' : ($recognition || $subjective ? 'recognition' : 'identity')),
            'attributed' => $recognition || $subjective,
            'review_required' => str_contains($lower, 'nữ hoàng'),
            'status' => 'CANDIDATE',
        ];
    }
}
