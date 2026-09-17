<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Video\YouTubeUrlNormalizer;
use NHK\Core\Domain\Capture\ContentIntent;

/**
 * Resolves the purpose of a new Capture before any Article draft is created.
 * It only classifies input; canonical owners and Governance remain writers.
 */
final class ContentIntentRouter
{
    /** @return array<string,mixed> */
    public function route(array $input, array $interpretation, array $assets): array
    {
        $explicit = strtoupper(trim((string) ($input['intent'] ?? '')));
        if ($explicit !== '') {
            $intent = ContentIntent::tryFrom($explicit);
            if (!$intent instanceof ContentIntent) throw new \InvalidArgumentException('CONTENT_INTENT_INVALID');
            $this->assertExplicitIntentIsValid($intent, $input, $assets);
            return $this->result($intent, 'EXPLICIT', $this->signals($input, $interpretation, $assets));
        }

        $signals = $this->signals($input, $interpretation, $assets);
        if ($signals['valid_video_url']) return $this->result(ContentIntent::VIDEO, 'HEURISTIC', $signals);
        if ($signals['atomic_delta']) return $this->result(ContentIntent::KNOWLEDGE_DELTA, 'HEURISTIC', $signals);
        if ($signals['has_assets'] && $signals['has_text']) return $this->result(ContentIntent::IMAGE_ARTICLE, 'HEURISTIC', $signals);
        if ($signals['has_text']) return $this->result(ContentIntent::TEXT_ARTICLE, 'HEURISTIC', $signals);

        return [
            'status' => 'ambiguous',
            'intent' => null,
            'source' => 'NONE',
            'article_required' => false,
            'diagnostics' => ['CONTENT_INTENT_AMBIGUOUS'],
            'signals' => $signals,
        ];
    }

    /** @param array<string,mixed> $persisted @param array<string,mixed> $input @param list<array<string,mixed>> $assets @return array<string,mixed> */
    public function reusePersisted(array $persisted, array $input, array $assets = []): array
    {
        $value = strtoupper(trim((string) ($persisted['intent'] ?? '')));
        $intent = ContentIntent::tryFrom($value);
        if (!$intent instanceof ContentIntent) throw new \InvalidArgumentException('CAPTURE_PERSISTED_CONTENT_INTENT_INVALID');
        $requested = strtoupper(trim((string) ($input['intent'] ?? '')));
        if ($requested !== '' && $requested !== $intent->value) throw new \InvalidArgumentException('CAPTURE_CONTENT_INTENT_CHANGE_NOT_ALLOWED');
        $signals = is_array($persisted['signals'] ?? null) ? $persisted['signals'] : [];
        $result = $this->result($intent, 'PERSISTED_CAPTURE', $signals);
        foreach (['purpose', 'semantic_delta'] as $handoffField) {
            if (array_key_exists($handoffField, $persisted)) $result[$handoffField] = $persisted[$handoffField];
        }
        $result['intent_reused'] = true;
        return $result;
    }

    /** @return array<string,mixed> */
    private function signals(array $input, array $interpretation, array $assets): array
    {
        $text = trim((string) ($input['text'] ?? $input['content'] ?? ''));
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?。！？])\s+/u', $text) ?: []), static fn (string $item): bool => $item !== ''));
        if ($sentences === [] && $text !== '') $sentences = [$text];
        $candidateCount = count(array_filter((array) ($interpretation['user_claim_candidates'] ?? []), 'is_array'));
        $validVideoUrl = false;
        $videoUrl = trim((string) (($input['video']['url'] ?? '') ?: ''));
        if ($videoUrl !== '') {
            try {
                YouTubeUrlNormalizer::normalize($videoUrl);
                $validVideoUrl = true;
            } catch (\Throwable) {
                $validVideoUrl = false;
            }
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $userArticleMarker = trim((string) ($metadata['editorial_intent'] ?? '')) !== ''
            || strtolower((string) ($metadata['content_kind'] ?? '')) === 'article'
            || preg_match('/\b(?:bài viết|tìm hiểu|giới thiệu)\b/u', $lower) === 1;
        $atomicDelta = !$userArticleMarker && $text !== '' && count($sentences) === 1 && $candidateCount === 1 && (
            preg_match('/\b\d+[\/.]?\d*\b/u', $text) === 1
            || preg_match('/\b(?:côn|búa|mặt|bản|phiên bản|thuộc|cấu hình|số)\b/u', $lower) === 1
        );

        return [
            'has_text' => $text !== '',
            'has_assets' => $assets !== [],
            'valid_video_url' => $validVideoUrl,
            'sentence_count' => count($sentences),
            'independent_fact_count' => $candidateCount,
            'standalone_readability' => $text !== '' && (count($sentences) > 1 || $candidateCount > 1 || trim((string) ($input['title'] ?? '')) !== ''),
            'user_article_marker' => $userArticleMarker,
            'atomic_delta' => $atomicDelta,
        ];
    }

    private function assertExplicitIntentIsValid(ContentIntent $intent, array $input, array $assets): void
    {
        if ($intent === ContentIntent::VIDEO) {
            try {
                YouTubeUrlNormalizer::normalize(trim((string) ($input['video']['url'] ?? '')));
            } catch (\Throwable) {
                throw new \InvalidArgumentException('VIDEO_INTENT_REQUIRES_VALID_YOUTUBE_URL');
            }
        }
        if (in_array($intent, [ContentIntent::IMAGE_ARTICLE, ContentIntent::MEDIA_ENRICHMENT], true) && $assets === []) {
            throw new \InvalidArgumentException($intent === ContentIntent::IMAGE_ARTICLE ? 'IMAGE_ARTICLE_REQUIRES_IMAGE' : 'MEDIA_ENRICHMENT_REQUIRES_IMAGE');
        }
        if ($intent->requiresArticle() && trim((string) ($input['text'] ?? $input['content'] ?? $input['title'] ?? '')) === '') {
            throw new \InvalidArgumentException('ARTICLE_INTENT_REQUIRES_EDITORIAL_CONTENT');
        }
    }

    /** @param array<string,mixed> $signals @return array<string,mixed> */
    private function result(ContentIntent $intent, string $source, array $signals): array
    {
        return [
            'status' => 'resolved',
            'intent' => $intent->value,
            'source' => $source,
            'article_required' => $intent->requiresArticle(),
            'media_required' => $intent->requiresMedia(),
            'diagnostics' => [],
            'signals' => $signals,
        ];
    }
}
