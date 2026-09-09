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
        foreach ($sentences as $sentence) $claims[] = [
            'text' => $sentence,
            'candidate_kind' => 'user_statement',
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            'scope' => 'capture',
            'status' => 'CANDIDATE',
        ];
        return [
            'primary_subject_hints' => array_values(array_unique(array_map('strval', $subjectHints))),
            'secondary_subject_hints' => [],
            'entity_mentions' => $mentions,
            'user_claim_candidates' => $claims,
            'relation_hints' => [],
            'article_intent' => $text,
            'uncertainty' => $text === '' ? ['EMPTY_INPUT'] : [],
            'asset_count' => count($assets),
        ];
    }
}
