<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;
use NHK\Core\Application\Seo\SeoReadinessPolicy;
use NHK\Core\Domain\Seo\SeoReadinessResult;

/** Shared, bounded, projection-only semantic SEO planner. */
final class SemanticSeoPlanner
{
    private const PROFILES = ['article', 'video', 'image', 'media'];
    private const STRUCTURED_TYPES = ['Article', 'VideoObject', 'ImageObject', 'WebPage'];

    public function plan(EditorialContextPack $pack, EditorialPlan $editorialPlan, EditorialDraft $draft, array $context = []): SemanticSeoPlan
    {
        $profile = strtolower(trim((string) ($pack->profile['profile'] ?? $editorialPlan->profile ?? 'article')));
        $identity = is_array($context['public_identity'] ?? null) ? $context['public_identity'] : [];
        $canonical = trim((string) ($identity['canonical_url'] ?? ''));
        $canonicalIdentity = ($identity['canonical_identity'] ?? $canonical !== '') === true;
        $publicEligible = ($identity['public_eligible'] ?? true) === true;
        $intent = $this->intent($pack->topic, $pack->selectedClaims);
        $title = $this->title($pack->topic, $profile);
        $h1 = trim($pack->topic);
        $meta = $this->meta($pack->topic, $pack->selectedClaims);
        (new PublicEditorialCopyGuard())->assertSafe($title);
        (new PublicEditorialCopyGuard())->assertSafe($h1);
        (new PublicEditorialCopyGuard())->assertSafe($meta);

        $readinessResult = (new SeoReadinessPolicy())->evaluate([
            'runtime_available' => ($context['runtime_available'] ?? true) === true,
            'applicable' => in_array($profile, self::PROFILES, true),
            'public_identity' => $canonical !== '' && $canonicalIdentity,
            'canonical_identity' => $canonicalIdentity,
            'canonical_url' => $canonical,
            'content_sufficient' => trim($draft->body) !== '',
            'public_eligible' => $publicEligible,
            'compliance' => 'PASS',
            'structured_data_applicable' => ($context['structured_data_applicable'] ?? true) === true,
        ]);
        $canonicalUrl = $readinessResult->status() === SeoReadinessResult::READY ? $canonical : null;
        $cluster = $this->cluster($pack->topic, $pack->selectedClaims, (array) ($context['dictionary_terms'] ?? []));
        $dictionary = $this->dictionary((array) ($context['dictionary_terms'] ?? []));
        $links = $this->links((array) ($context['internal_link_candidates'] ?? []), $canonicalUrl);
        $cannibalization = $this->cannibalization($pack->primarySubject, $intent, (array) ($context['competing_pages'] ?? []));
        $structured = $this->structured($context['structured_data'] ?? null, $canonicalUrl, $title, $pack->selectedClaims);
        $trace = array_values(array_map(static fn (array $claim): array => ['claim_id' => (string) ($claim['claim_id'] ?? ''), 'claim_revision' => max(1, (int) ($claim['claim_revision'] ?? 1)), 'original_subject' => $claim['original_subject'] ?? [], 'editorial_role' => (string) ($claim['editorial_role'] ?? '')], array_filter($pack->selectedClaims, 'is_array')));
        $diagnostics = ['cannibalization' => $cannibalization, 'profile' => $profile, 'selected_claim_count' => count($trace), 'projection_only' => true, 'policy_version' => 'semantic-seo-v1'];
        if ($profile === '' || !in_array($profile, self::PROFILES, true)) $readinessResult = new \NHK\Core\Domain\Seo\SeoReadinessResult(SeoReadinessResult::NOT_APPLICABLE, ['PROFILE_UNSUPPORTED']);
        return new SemanticSeoPlan($readinessResult->status(), $profile, $intent, $pack->primarySubject, $pack->topic, $cluster, $title, $h1, $meta, $canonicalUrl, ['title' => $title, 'description' => $meta, 'canonical' => $canonicalUrl], $links, $dictionary, $structured, $trace, $diagnostics, $readinessResult->reasons());
    }

    private function intent(string $topic, array $claims): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($topic) : strtolower($topic);
        if (preg_match('/\b(vách|máy|phiên bản|nhận biết|cấu hình)\b/u', $value) === 1) return 'machine-recognition';
        if (preg_match('/\b(video|hình ảnh|ảnh)\b/u', $value) === 1) return 'visual-context';
        return $claims === [] ? 'topic-overview' : 'subject-explanation';
    }

    private function title(string $topic, string $profile): string
    {
        $suffix = $profile === 'video' ? ' — Video và hướng dẫn nhận biết' : ' — Hướng dẫn nhận biết';
        return trim($topic) . $suffix;
    }

    private function meta(string $topic, array $claims): string
    {
        $extra = '';
        foreach ($claims as $claim) if (is_array($claim) && trim((string) ($claim['text'] ?? '')) !== '') { $extra = ' ' . trim((string) $claim['text']); break; }
        $text = trim($topic) . ' — ' . trim($extra, " .!?\t\n\r\0\x0B");
        return function_exists('mb_substr') ? mb_substr($text, 0, 155) : substr($text, 0, 155);
    }

    private function cluster(string $topic, array $claims, array $dictionary): array
    {
        $values = [$topic];
        foreach ($claims as $claim) if (is_array($claim)) $values[] = (string) ($claim['text'] ?? '');
        foreach ($dictionary as $term) if (is_array($term)) $values[] = (string) ($term['term'] ?? '');
        $result = []; $seen = [];
        foreach ($values as $value) foreach (preg_split('/[^\p{L}\p{N}]+/u', trim($value)) ?: [] as $word) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($word) : strtolower($word);
            if ($key === '' || in_array($key, ['của', 'và', 'là', 'có', 'đây', 'một', 'những'], true) || isset($seen[$key])) continue;
            $seen[$key] = true; $result[] = $word;
            if (count($result) >= 16) return $result;
        }
        return $result;
    }

    private function dictionary(array $items): array
    {
        $result = [];
        foreach (array_slice($items, 0, 8) as $item) {
            if (!is_array($item) || ($item['public_eligible'] ?? true) !== true) continue;
            $owner = is_array($item['canonical_owner'] ?? null) ? $item['canonical_owner'] : [];
            $url = trim((string) ($owner['url'] ?? $item['url'] ?? ''));
            if ($url === '' || ($owner !== [] && ($owner['public_eligible'] ?? false) !== true)) continue;
            $result[] = ['term' => (string) ($item['term'] ?? ''), 'concept_id' => (string) ($item['concept_id'] ?? ''), 'url' => $url, 'owner_id' => (string) ($owner['id'] ?? '')];
        }
        return $result;
    }

    private function links(array $items, ?string $canonicalUrl): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['public_eligible'] ?? false) !== true) continue;
            $url = trim((string) ($item['url'] ?? $item['canonical_url'] ?? ''));
            if ($url === '' || $url === $canonicalUrl || !str_starts_with($url, '/') || preg_match('#^/(?:wp-|private|admin|internal|[0-9a-f]{8}-[0-9a-f-]{27,})#i', $url) === 1) continue;
            if ((float) ($item['semantic_relevance'] ?? 0.0) <= 0.0) continue;
            $result[] = ['destination_id' => (string) ($item['id'] ?? ''), 'label' => (string) ($item['label'] ?? ''), 'url' => $url, 'reason' => 'semantic continuation'];
            if (count($result) >= 5) break;
        }
        return $result;
    }

    private function cannibalization(array $subject, string $intent, array $pages): array
    {
        $reasons = [];
        foreach ($pages as $page) if (is_array($page) && (string) ($page['subject_id'] ?? '') === (string) ($subject['id'] ?? '') && (string) ($page['intent'] ?? '') === $intent) $reasons[] = 'DUPLICATE_INTENT_REVIEW';
        return ['status' => $reasons === [] ? 'clustered' : 'review', 'reasons' => array_values(array_unique($reasons)), 'checked_count' => count($pages)];
    }

    private function structured(mixed $input, ?string $canonicalUrl, string $title, array $claims): array
    {
        $type = is_array($input) ? (string) ($input['type'] ?? '') : '';
        if (!in_array($type, self::STRUCTURED_TYPES, true) || $canonicalUrl === null) return [];
        return ['type' => $type, 'name' => $title, 'url' => $canonicalUrl, 'visible_claim_ids' => array_values(array_map(static fn (array $claim): string => (string) ($claim['claim_id'] ?? ''), array_filter($claims, static fn (mixed $claim): bool => is_array($claim) && ($claim['eligibility'] ?? '') === 'eligible')))] ;
    }
}
