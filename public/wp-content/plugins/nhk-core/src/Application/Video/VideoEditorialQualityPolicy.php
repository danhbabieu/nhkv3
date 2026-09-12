<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\{VideoEditorialEnrichmentContext, VideoEditorialQuality};

/** Deterministic gate for useful Video editorial projection. */
final class VideoEditorialQualityPolicy
{
    /** @param array<string,mixed> $editorial */
    public function evaluate(array $editorial, ?VideoEditorialEnrichmentContext $context = null): VideoEditorialQuality
    {
        $blockers = [];
        foreach (['title', 'summary', 'body', 'why_this_matters'] as $field) {
            if (trim((string) ($editorial[$field] ?? '')) === '') $blockers[] = 'EDITORIAL_' . strtoupper($field) . '_MISSING';
        }

        $title = $this->normalize((string) ($editorial['title'] ?? ''));
        $summary = $this->normalize((string) ($editorial['summary'] ?? ''));
        $body = $this->normalize((string) ($editorial['body'] ?? ''));
        $why = $this->normalize((string) ($editorial['why_this_matters'] ?? ''));
        $words = preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($summary !== '' && in_array($summary, [$title, $body], true)) $blockers[] = 'EDITORIAL_SUMMARY_TRIVIAL';
        if ($body !== '' && in_array($body, [$title, $summary], true)) $blockers[] = 'EDITORIAL_BODY_TRIVIAL';
        if ($body !== '' && count($words) < 30) $blockers[] = 'EDITORIAL_BODY_TRIVIAL';
        if ($why !== '' && $why === $title) $blockers[] = 'EDITORIAL_WHY_THIS_MATTERS_TRIVIAL';
        if ($this->containsUnsupportedUniversal((string) ($editorial['body'] ?? '') . ' ' . (string) ($editorial['summary'] ?? ''))) $blockers[] = 'UNSUPPORTED_UNIVERSAL_CLAIM';

        $context ??= VideoEditorialEnrichmentContext::fromArray([]);
        if ($context->specimenFacts !== [] && !$this->containsAny($body, ['chính hiện vật', 'chiếc xuất hiện trong video', 'hiện vật được ghi lại'])) $blockers[] = 'SPECIMEN_SCOPE_NOT_EXPLICIT';
        if ($context->canonicalContext !== [] && !$this->containsCanonicalContext($body, $context->canonicalContext)) $blockers[] = 'CANONICAL_CONTEXT_NOT_USED';
        if ($context->relatedKnowledge !== [] && !is_array($editorial['related_knowledge'] ?? null)) $blockers[] = 'RELATED_KNOWLEDGE_NOT_ATTACHED';
        if ($context->relatedKnowledge !== [] && (array) ($editorial['related_knowledge'] ?? []) === []) $blockers[] = 'RELATED_KNOWLEDGE_NOT_ATTACHED';
        if ($context->relatedEntities !== [] && (array) ($editorial['related_entities'] ?? []) === []) $blockers[] = 'RELATED_ENTITIES_NOT_ATTACHED';
        $blockers = array_values(array_unique($blockers));
        return new VideoEditorialQuality($blockers === [] ? VideoEditorialQuality::COMPLETE : VideoEditorialQuality::NEEDS_REVIEW, $blockers);
    }

    private function containsUnsupportedUniversal(string $value): bool
    {
        $value = $this->normalize($value);
        return preg_match('/\b(?:tất cả|mọi chiếc|duy nhất|luôn luôn|tốt nhất|đẹp nhất|hiếm nhất|số 1|number one|the best)\b/u', $value) === 1;
    }

    /** @param list<array<string,mixed>> $rows */
    private function containsCanonicalContext(string $body, array $rows): bool
    {
        foreach ($rows as $row) {
            $text = $this->normalize((string) ($row['text'] ?? $row['name'] ?? $row['title'] ?? ''));
            if ($text !== '' && str_contains($body, $text)) return true;
        }
        return false;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) if (str_contains($haystack, $needle)) return true;
        return false;
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
