<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;
use NHK\Core\Shared\Encoding\Utf8String;

/** Shared deterministic composer for Article, Video and Image/Media drafts. */
final class SharedEditorialComposer
{
    public function __construct(private ?PublicEditorialCopyGuard $publicCopyGuard = null)
    {
    }

    public function compose(EditorialPlan $plan): EditorialDraft
    {
        $guard = $this->publicCopyGuard ?? new PublicEditorialCopyGuard();
        // Capture keeps raw instructions for orchestration, but only typed
        // editorial copy may enter public Article prose.
        $articleComposition = $plan->profile === 'article';
        $input = $this->normalizeInput((string) (array_key_exists('editorial_copy', $plan->inputContext)
            ? $plan->inputContext['editorial_copy']
            : ($plan->inputContext['raw_input'] ?? $plan->inputContext['text'] ?? '')), $articleComposition);
        $title = $this->title($plan->topic, $input, $articleComposition ? (string) ($plan->inputContext['title'] ?? '') : '');
        $paragraphs = [];
        $opening = $input !== '' ? $input : $this->normalizeSentence($plan->topic);
        if ($opening !== '') $paragraphs[] = $opening;

        $trace = [];
        $claimTexts = [];
        $traceFailure = false;
        $seenClaimText = [];
        foreach ($plan->sections as $section) {
            if (($section['id'] ?? '') === 'opening') continue;
            $realized = [];
            foreach ((array) ($section['claims'] ?? []) as $index => $claim) {
                if (!is_array($claim)
                    || ($claim['eligibility'] ?? '') !== 'eligible'
                    || ($claim['publicly_composable'] ?? true) !== true
                    || (($claim['applicability'] ?? 'applicable') !== 'applicable')
                    || trim((string) ($claim['claim_id'] ?? '')) === '') {
                    $traceFailure = true;
                    continue;
                }
                // Public composition remains fail-closed for legacy/stale
                // packets that incorrectly mark internal grounding as public.
                $semanticRole = strtoupper(trim((string) ($claim['semantic_role'] ?? '')));
                if (in_array($semanticRole, [
                    EditorialSemanticRolePolicy::PROVENANCE_ONLY,
                    EditorialSemanticRolePolicy::GROUNDING,
                    EditorialSemanticRolePolicy::CONTROL_ONLY,
                ], true)) {
                    $traceFailure = true;
                    continue;
                }
                $text = trim((string) ($claim['text'] ?? $claim['claim_text'] ?? ''));
                if ($text === '') { $traceFailure = true; continue; }
                try { $guard->assertSafe($text); } catch (\Throwable) { $traceFailure = true; continue; }

                $editorialTreatment = strtoupper(trim((string) ($claim['editorial_treatment'] ?? '')));
                if (($claim['semantic_context_only'] ?? false) === true
                    && !in_array($editorialTreatment, ['SUPPORTING_CONTEXT', 'BACKGROUND_CONTEXT', 'COMPARATIVE_CONTEXT'], true)) {
                    $editorialTreatment = 'SUPPORTING_CONTEXT';
                }
                $trace[] = [
                    'claim_id' => (string) $claim['claim_id'],
                    'claim_revision' => max(1, (int) ($claim['claim_revision'] ?? 1)),
                    'section_id' => (string) ($section['id'] ?? ''),
                    'original_subject' => $claim['original_subject'] ?? [],
                    'target_subject' => $claim['resolved_primary_subject'] ?? $claim['target_subject'] ?? [],
                    'scope' => (string) ($claim['scope'] ?? ''),
                    'applicability' => (string) ($claim['applicability'] ?? 'applicable'),
                    'specificity' => $claim['specificity'] ?? $claim['semantic_specificity'] ?? null,
                    'graph_path' => $claim['graph_path'] ?? [],
                    'retrieval_tier' => (string) ($claim['retrieval_tier'] ?? 'EXACT'),
                    'coverage_kind' => (string) ($claim['coverage_kind'] ?? 'exact'),
                    'editorial_treatment' => $editorialTreatment !== '' ? $editorialTreatment : 'DIRECT_FACT',
                    'semantic_context_only' => ($claim['semantic_context_only'] ?? false) === true,
                    'editorial_role' => (string) ($claim['editorial_role'] ?? ''),
                    'selection_reason' => (string) ($claim['selection_reason'] ?? ''),
                ];
                $claimTexts[] = $text;
                $key = $this->comparisonKey($text);
                if ($key === '' || isset($seenClaimText[$key])) continue;
                $seenClaimText[$key] = true;
                $realized[] = $this->realizeClaim($text, (string) ($claim['editorial_role'] ?? ''), (int) $index, count($trace));
            }
            if ($realized !== []) $paragraphs[] = implode(' ', $realized);
        }

        $body = implode("\n\n", array_map(fn (string $paragraph): string => $this->normalizeParagraph($paragraph, $articleComposition), $paragraphs));
        $guard->assertSafe($title);
        $guard->assertSafe($body);
        $status = $traceFailure ? 'review' : ($trace === [] ? ($input !== '' ? 'sparse_input' : 'review') : 'available');
        $novelty = $this->informationGain($claimTexts, $plan->inputContext);
        return new EditorialDraft($status, $plan->profile, $title, $this->summary($paragraphs[0], $articleComposition ? (string) ($plan->inputContext['excerpt'] ?? '') : ''), $body, $trace, [
            'mode' => $trace === [] ? ($input !== '' ? 'sparse_input' : 'no_selected_knowledge') : 'selected_knowledge',
            'information_gain' => $novelty,
            'visual_support' => $plan->visualSupport,
            'traceability' => $traceFailure ? 'FAILED' : 'PASSED',
            'source_input' => $input,
        ]);
    }

    private function realizeClaim(string $text, string $role, int $index, int $tracePosition): string
    {
        $sentence = $this->normalizeSentence($text);
        $role = strtoupper(trim($role));
        if ($role === 'CORE' && $index === 0) return $sentence;
        if ($this->startsWithAny($sentence, ['một dấu hiệu', 'để hiểu rõ hơn', 'trong bối cảnh này', 'khi so sánh', 'tiếp theo'])) return $sentence;
        $prefixes = match ($role) {
            'IDENTIFICATION' => ['Một dấu hiệu dễ nhận ra là ', 'Có thể nhận biết qua '],
            'EXPLANATION' => ['Để hiểu rõ hơn, ', 'Ở khía cạnh này, '],
            'COMPARISON' => ['Khi so sánh, ', 'Điểm khác biệt thể hiện ở '],
            'NEXT_STEP' => ['Tiếp theo, ', 'Một hướng tìm hiểu thêm là '],
            'CONTEXT' => ['Trong bối cảnh này, ', 'Ngoài thông tin chính, '],
            default => ['Ngoài ra, ', 'Bên cạnh đó, '],
        };
        $prefix = $prefixes[($tracePosition + $index) % count($prefixes)];
        return $prefix . $this->lowerFirst($sentence);
    }

    private function normalizeInput(string $input, bool $preserveMarkdown = false): string
    {
        $input = trim(str_replace(["\r\n", "\r"], "\n", $input));
        if ($input === '') return '';
        if ($preserveMarkdown && preg_match('/^\s*#{1,6}\s+/m', $input) === 1) {
            $lines = array_map(function (string $line): string {
                $line = trim($line);
                if ($line === '') return '';
                if (preg_match('/^#{1,6}\s+/', $line) === 1) return (string) (preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
                return $this->normalizeSentence((string) (preg_replace('/[ \t]+/u', ' ', $line) ?? $line));
            }, explode("\n", $input));
            return trim(implode("\n", $lines));
        }
        $input = trim((string) (preg_replace('/\s+/u', ' ', $input) ?? $input));
        return $this->normalizeSentence($input);
    }

    private function normalizeParagraph(string $paragraph, bool $preserveMarkdown = false): string
    {
        if ($preserveMarkdown && preg_match('/^\s*#{1,6}\s+/m', $paragraph) === 1) {
            return trim(implode("\n", array_map(static fn (string $line): string => trim((string) (preg_replace('/[ \t]+/u', ' ', $line) ?? $line)), explode("\n", $paragraph))));
        }
        return trim((string) (preg_replace('/\s+/u', ' ', $paragraph) ?? $paragraph));
    }

    private function normalizeSentence(string $text): string
    {
        $text = trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
        if ($text === '') return '';
        $text = (string) (preg_replace('/[.!?。！？]{2,}$/u', '.', $text) ?? $text);
        return preg_match('/[.!?。！？]$/u', $text) === 1 ? $text : $text . '.';
    }

    private function startsWithAny(string $value, array $prefixes): bool
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        foreach ($prefixes as $prefix) if (str_starts_with($lower, $prefix)) return true;
        return false;
    }

    private function lowerFirst(string $value): string
    {
        if ($value === '') return $value;
        if (function_exists('mb_strtolower')) return mb_strtolower(Utf8String::slice($value, 0, 1, 'shared.editorial.composer', 'lower_first')) . Utf8String::slice($value, 1, null, 'shared.editorial.composer', 'lower_first');
        return strtolower(Utf8String::slice($value, 0, 1, 'shared.editorial.composer', 'lower_first')) . Utf8String::slice($value, 1, null, 'shared.editorial.composer', 'lower_first');
    }

    private function comparisonKey(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        return trim((string) (preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text));
    }

    private function title(string $topic, string $input, string $explicitTitle = ''): string
    {
        $value = trim($explicitTitle !== '' ? $explicitTitle : ($topic !== '' ? $topic : $input));
        return Utf8String::truncate($value, 120, 'shared.editorial.composer', 'title');
    }

    private function summary(string $first, string $explicitExcerpt = ''): string
    {
        $value = trim($explicitExcerpt !== '' ? $explicitExcerpt : $first);
        return Utf8String::truncate($value, 180, 'shared.editorial.composer', 'summary');
    }

    private function informationGain(array $claimTexts, array $input): float
    {
        if ($claimTexts === []) return 0.0;
        $inputWords = preg_split('/[^\p{L}\p{N}]+/u', function_exists('mb_strtolower') ? mb_strtolower((string) ($input['raw_input'] ?? '')) : strtolower((string) ($input['raw_input'] ?? ''))) ?: [];
        $claimWords = preg_split('/[^\p{L}\p{N}]+/u', function_exists('mb_strtolower') ? mb_strtolower(implode(' ', $claimTexts)) : strtolower(implode(' ', $claimTexts))) ?: [];
        return round(count(array_diff($claimWords, $inputWords)) / max(1, count($claimWords)), 6);
    }
}
