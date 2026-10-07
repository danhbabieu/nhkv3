<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/**
 * Keeps Knowledge proposal input bound to the structured semantic packet.
 * Dictionary wording is lexical-owner input and is never a Knowledge claim.
 */
final class SemanticClaimCandidateGuard
{
    /** @return array<string,mixed> */
    public function evaluate(array $candidate, array $interpretation): array
    {
        $packet = is_array($interpretation['structured_interpretation_packet'] ?? null)
            ? $interpretation['structured_interpretation_packet']
            : [];
        $commands = array_values(array_filter((array) ($packet['dictionary_owner_commands'] ?? []), 'is_array'));
        if ($commands === []) return ['status' => 'ALLOWED', 'reason' => 'NO_DICTIONARY_COMMAND'];

        $text = $this->normalize((string) ($candidate['text'] ?? ''));
        if ($text === '') return $this->review('KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED');
        if (($candidate['candidate_source'] ?? '') === 'CONTINUATION_DELTA') {
            return ['status' => 'ALLOWED', 'reason' => 'EXPLICIT_CONTINUATION_DELTA'];
        }

        $assertionTexts = [];
        foreach ((array) ($packet['semantic_assertions'] ?? []) as $assertion) {
            if (!is_array($assertion)) continue;
            $assertionText = $this->normalize((string) ($assertion['text'] ?? ''));
            if ($assertionText !== '') $assertionTexts[] = $assertionText;
        }
        if (in_array($text, array_values(array_unique($assertionTexts)), true)) {
            return ['status' => 'ALLOWED', 'reason' => 'STRUCTURED_SEMANTIC_ASSERTION'];
        }
        return $this->review('KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED', [
            'candidate_text' => (string) ($candidate['text'] ?? ''),
            'structured_assertions' => count($assertionTexts),
        ]);
    }

    private function normalize(string $value): string
    {
        $value = trim($value, " \t\n\r.,;:!?。！？");
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    /** @param array<string,mixed> $diagnostics @return array<string,mixed> */
    private function review(string $blocker, array $diagnostics = []): array
    {
        return ['status' => 'REVIEW_REQUIRED', 'blockers' => [$blocker], 'diagnostics' => $diagnostics];
    }
}
