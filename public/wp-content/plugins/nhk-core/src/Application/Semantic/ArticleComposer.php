<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;

/** Deterministic editorial composer. Canonical Claim text is never dumped verbatim. */
final class ArticleComposer
{
    public function __construct(private ?PublicEditorialCopyGuard $publicCopyGuard = null) {}

    /** @param list<array<string,mixed>> $observations @param list<array<string,mixed>> $selectedClaims @return array<string,mixed> */
    public function compose(string $userInput, array $observations, array $selectedClaims, array $context = []): array
    {
        $userInput = trim($userInput);
        $priorSections = array_values(array_filter((array) ($context['prior_composition']['managed_sections'] ?? []), 'is_array'));
        $userInput = $this->removeOwnedSections($userInput, $priorSections);
        $title = trim((string) ($context['title'] ?? ''));
        if ($title === '') $title = $this->title($userInput);
        $paragraphs = [];
        $managedSections = [];
        if ($userInput !== '') $paragraphs[] = $userInput;
        foreach ($observations as $observation) {
            if (!is_array($observation)) continue;
            if (($context['asset_count'] ?? null) === 0) continue;
            if (($observation['provenance'] ?? '') !== 'OBSERVED_FROM_MEDIA') continue;
            $mediaId = trim((string) ($observation['media_id'] ?? ''));
            if ($mediaId === '') continue;
            if (isset($context['assets']) && !$this->hasAsset((array) $context['assets'], $mediaId)) continue;
            $text = trim((string) ($observation['observation'] ?? $observation['text'] ?? ''));
            if ($text !== '') $paragraphs[] = 'Quan sát từ tư liệu gửi kèm cho thấy ' . rtrim($text, '.!?') . '.';
        }
        $seenClaims = [];
        foreach ($selectedClaims as $claim) {
            if (!is_array($claim)) continue;
            $claimId = trim((string) ($claim['claim_id'] ?? $claim['id'] ?? ''));
            $claimRevision = max(1, (int) ($claim['claim_revision'] ?? $claim['revision'] ?? 1));
            $semanticKey = $claimId !== '' ? 'claim-context:' . $claimId . ':' . $claimRevision : '';
            if ($semanticKey !== '' && isset($seenClaims[$semanticKey])) continue;
            if ($semanticKey !== '') $seenClaims[$semanticKey] = true;
            $summary = $this->claimSummary((string) ($claim['text'] ?? ''));
            if ($summary !== '') {
                $content = 'Trong bối cảnh hồ sơ đã được kiểm chứng, nội dung này được đặt cạnh ghi nhận rằng ' . $summary . '.';
                $paragraphs[] = $content;
                $managedSections[] = ['semantic_key' => $semanticKey !== '' ? $semanticKey : 'claim-context:' . hash('sha256', $content), 'origin' => 'CANONICAL_CLAIM', 'fingerprint' => hash('sha256', $content), 'content' => $content];
            }
        }
        if ($paragraphs === []) $paragraphs[] = 'Nội dung đang chờ bổ sung dữ liệu biên tập.';
        $trace = [];
        $traceKeys = [];
        foreach ($selectedClaims as $claim) {
            if (!is_array($claim)) continue;
            $claimId = (string) ($claim['claim_id'] ?? $claim['id'] ?? '');
            $claimRevision = max(1, (int) ($claim['claim_revision'] ?? $claim['revision'] ?? 1));
            $traceKey = $claimId !== '' ? $claimId . ':' . $claimRevision : hash('sha256', json_encode($claim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (isset($traceKeys[$traceKey])) continue;
            $traceKeys[$traceKey] = true;
            $trace[] = [
                'claim_id' => $claimId,
                'claim_revision' => $claimRevision,
                'subject_id' => (string) ($claim['subject_id'] ?? ''),
                'usage_role' => 'supporting_fact',
                'relation_path' => is_array($claim['relation_path'] ?? null) ? $claim['relation_path'] : [],
                'scope' => (string) ($claim['scope'] ?? ''),
                'provenance' => (string) ($claim['provenance'] ?? ''),
                'evidence_status' => (string) ($claim['evidence_status'] ?? ''),
                'composition_reason' => (string) ($claim['reason'] ?? 'bounded relevant canonical knowledge'),
            ];
        }
        $explicitExcerpt = trim((string) ($context['excerpt'] ?? ''));
        $content = implode("\n\n", $paragraphs);
        $guard = $this->publicCopyGuard ?? new PublicEditorialCopyGuard();
        $guard->assertSafe($title);
        $guard->assertSafe($explicitExcerpt !== '' ? $explicitExcerpt : $this->excerpt($paragraphs[0]));
        $guard->assertSafe($content);
        return [
            'title' => $title,
            'excerpt' => $explicitExcerpt !== '' ? $explicitExcerpt : $this->excerpt($paragraphs[0]),
            'content' => $content,
            'managed_sections' => $managedSections,
            'origin_ownership' => ['user_authored' => $userInput !== '', 'managed_origins' => array_values(array_unique(array_map(static fn (array $section): string => (string) ($section['origin'] ?? 'SYSTEM_DERIVED'), $managedSections)))],
            'claim_trace' => $trace,
            'research_snapshot' => ['claims' => array_map(static fn (array $item): array => ['claim_id' => $item['claim_id'], 'revision' => $item['claim_revision']], $trace), 'composer_revision' => 1],
            'composition_revision' => 1,
        ];
    }

    /** Remove only prior structured managed sections; all other user text remains intact. */
    private function removeOwnedSections(string $input, array $sections): string
    {
        foreach ($sections as $section) {
            $owned = trim((string) ($section['content'] ?? ''));
            if ($owned === '') continue;
            $input = str_replace(["\n\n" . $owned, $owned . "\n\n", $owned], ['', '', ''], $input);
        }
        return trim(preg_replace('/\n{3,}/', "\n\n", $input) ?? $input);
    }

    private function title(string $input): string
    {
        $first = trim((string) (preg_split('/(?<=[.!?。！？])\s+/u', $input)[0] ?? $input));
        return $first !== '' ? (function_exists('mb_substr') ? mb_substr($first, 0, 96) : substr($first, 0, 96)) : 'Bản ghi biên tập mới';
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 180) : substr($text, 0, 180);
    }

    private function claimSummary(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';
        $text = rtrim($text, '.!?。！？');
        $text = preg_replace('/^(.{0,80}?)(?:\s+)(?:là|là một)\s+/ui', '$1 được ghi nhận là ', $text) ?? $text;
        // Keep the source boundary explicit so a Claim is synthesized rather
        // than dumped verbatim into editorial prose.
        return $text . ' [trong phạm vi đã kiểm chứng]';
    }

    /** @param list<array<string,mixed>> $assets */
    private function hasAsset(array $assets, string $mediaId): bool
    {
        foreach ($assets as $asset) if (is_array($asset) && trim((string) ($asset['media_id'] ?? '')) === $mediaId) return true;
        return false;
    }
}
