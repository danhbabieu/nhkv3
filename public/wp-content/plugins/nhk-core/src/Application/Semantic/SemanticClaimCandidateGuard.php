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
        $text = $this->normalize((string) ($candidate['text'] ?? ''));
        if ($text === '') return $this->review('KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED');
        if (($candidate['review_required'] ?? false) === true || strtoupper(trim((string) ($candidate['status'] ?? ''))) === 'REVIEW_REQUIRED') {
            return $this->review('KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED', ['candidate_status' => (string) ($candidate['status'] ?? 'REVIEW_REQUIRED')]);
        }

        $commands = array_values(array_filter((array) ($packet['dictionary_owner_commands'] ?? []), 'is_array'));
        $sourceContext = is_array($packet['source_context'] ?? null) ? $packet['source_context'] : [];
        $rawOrDerived = strtoupper(trim((string) ($candidate['raw_or_derived'] ?? $sourceContext['raw_or_derived'] ?? 'RAW')));
        if ($commands === []
            && ($candidate['candidate_kind'] ?? '') === 'user_statement'
            && strtoupper(trim((string) ($candidate['provenance'] ?? ''))) === 'EXPLICIT_USER_KNOWLEDGE'
            && $rawOrDerived !== 'DERIVED'
        ) {
            return ['status' => 'ALLOWED', 'reason' => 'RAW_EXPLICIT_USER_KNOWLEDGE'];
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
