<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;

/** Deterministic editorial composer. Canonical Claim text is never dumped verbatim. */
final class ArticleComposer
{
    public function __construct(private ?PublicEditorialCopyGuard $publicCopyGuard = null, private ?ManagedArticleSectionParser $sectionParser = null, private ?EditorialProjectionEligibility $projectionEligibility = null) {}

    /** @param list<array<string,mixed>> $observations @param list<array<string,mixed>> $selectedClaims @return array<string,mixed> */
    public function compose(string $userInput, array $observations, array $selectedClaims, array $context = []): array
    {
        $userInput = trim($userInput);
        $priorSections = array_values(array_filter((array) ($context['prior_composition']['managed_sections'] ?? []), 'is_array'));
        $sectionParser = $this->sectionParser ?? new ManagedArticleSectionParser();
        // Composition owns the complete NHK-managed section set for this
        // Article. Remove stale NHK markers even when an old receipt lost its
        // manifest, while preserving every user-authored paragraph.
        $userInput = $sectionParser->reconcileStale($userInput, $priorSections);
        $userInput = $sectionParser->removeOwned($userInput, $priorSections);
        $title = trim((string) ($context['title'] ?? ''));
        if ($title === '') $title = $this->title($userInput);
        $guard = $this->publicCopyGuard ?? new PublicEditorialCopyGuard();
        $projectionEligibility = $this->projectionEligibility ?? new EditorialProjectionEligibility();
        // Canonical claims remain available to compliance/research, but only
        // reader-safe claims may be projected into public prose. An unsafe
        // legacy claim must not poison an otherwise safe editorial rewrite.
        $usableClaims = array_values(array_filter($selectedClaims, static function (mixed $claim) use ($guard, $projectionEligibility, $context): bool {
            if (!is_array($claim)) return false;
            $text = trim((string) ($claim['text'] ?? ''));
            if ($text === '') return false;
            if (($projectionEligibility->evaluate($claim, $context)['eligible'] ?? false) !== true) return false;
            try { $guard->assertSafe($text); return true; } catch (\Throwable) { return false; }
        }));
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
        $dependencyFingerprint = $this->dependencyFingerprint($selectedClaims, $observations, $context);
        foreach ($usableClaims as $claim) {
            if (!is_array($claim)) continue;
            $claimId = trim((string) ($claim['claim_id'] ?? $claim['id'] ?? ''));
            $claimRevision = max(1, (int) ($claim['claim_revision'] ?? $claim['revision'] ?? 1));
            $semanticKey = $claimId !== '' ? 'claim-context:' . $claimId . ':' . $claimRevision : '';
            if ($semanticKey !== '' && isset($seenClaims[$semanticKey])) continue;
            if ($semanticKey !== '') $seenClaims[$semanticKey] = true;
            $summary = $this->claimSummary((string) ($claim['text'] ?? ''));
            if ($summary !== '') {
                $content = 'Một điểm đáng chú ý để người sưu tầm đối chiếu là ' . $this->lowerFirst($summary) . '.';
                $semanticKey = $semanticKey !== '' ? $semanticKey : 'claim-context:' . hash('sha256', $content);
                $fingerprint = hash('sha256', $content);
                $sectionId = 'nhk-managed-' . hash('sha256', $semanticKey);
                $sectionMetadata = ['origin' => 'CANONICAL_CLAIM', 'semantic_owner' => 'knowledge', 'editorial_purpose' => 'bounded_supporting_fact', 'regeneration_policy' => 'REGENERATE_ON_DEPENDENCY_CHANGE'];
                $paragraphs[] = $sectionParser->wrap($sectionId, $fingerprint, $dependencyFingerprint, $content, $sectionMetadata);
                $managedSections[] = ['section_id' => $sectionId, 'semantic_key' => $semanticKey, 'origin' => 'CANONICAL_CLAIM', 'semantic_owner' => 'knowledge', 'editorial_purpose' => 'bounded_supporting_fact', 'regeneration_policy' => 'REGENERATE_ON_DEPENDENCY_CHANGE', 'fingerprint' => $fingerprint, 'dependency_fingerprint' => $dependencyFingerprint, 'content' => $content];
            }
        }
        if ($paragraphs === []) $paragraphs[] = 'Nội dung đang chờ bổ sung dữ liệu biên tập.';
        $trace = [];
        $traceKeys = [];
        foreach ($usableClaims as $claim) {
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
            'research_snapshot' => ['claims' => array_map(static fn (array $item): array => ['claim_id' => $item['claim_id'], 'revision' => $item['claim_revision']], $trace), 'composer_revision' => max(1, (int) ($context['prior_composition']['research_snapshot']['composer_revision'] ?? 0) + 1)],
            'dependency_fingerprint' => $dependencyFingerprint,
            'composition_revision' => max(1, (int) ($context['prior_composition']['composition_revision'] ?? 0) + 1),
        ];
    }

    private function dependencyFingerprint(array $claims, array $observations, array $context): string
    {
        if (isset($context['dependency_fingerprint']) && is_string($context['dependency_fingerprint']) && $context['dependency_fingerprint'] !== '') return $context['dependency_fingerprint'];
        $dependencies = [
            'claims' => array_values(array_map(static fn (mixed $claim): array => is_array($claim) ? ['id' => (string) ($claim['claim_id'] ?? $claim['id'] ?? ''), 'revision' => max(1, (int) ($claim['claim_revision'] ?? $claim['revision'] ?? 1))] : [], $claims)),
            'observations' => $observations,
            'media_usages' => $context['media_usages'] ?? [],
            'visual_support' => $context['visual_support'] ?? [],
            'public_identity' => $context['public_identity'] ?? [],
            'editorial_state_token' => (string) ($context['editorial_state_token'] ?? ''),
        ];
        return hash('sha256', json_encode($dependencies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
        $text = preg_replace('/^Trong bối cảnh hồ sơ đã được kiểm chứng,\s*/ui', '', $text) ?? $text;
        $text = preg_replace('/\s*\[trong phạm vi đã kiểm chứng\]\s*$/ui', '', $text) ?? $text;
        $text = rtrim($text, '.!?。！？');
        $text = preg_replace('/^(.{0,80}?)(?:\s+)(?:là|là một)\s+/ui', '$1 được ghi nhận là ', $text) ?? $text;
        // Keep the source boundary explicit so a Claim is synthesized rather
        // than dumped verbatim into editorial prose.
        return $text;
    }

    private function lowerFirst(string $text): string
    {
        if ($text === '') return '';
        if (function_exists('mb_strtolower') && function_exists('mb_substr')) return mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
        return strtolower(substr($text, 0, 1)) . substr($text, 1);
    }

    /** @param list<array<string,mixed>> $assets */
    private function hasAsset(array $assets, string $mediaId): bool
    {
        foreach ($assets as $asset) if (is_array($asset) && trim((string) ($asset['media_id'] ?? '')) === $mediaId) return true;
        return false;
    }
}
