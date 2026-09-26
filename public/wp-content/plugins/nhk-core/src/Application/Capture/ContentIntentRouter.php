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
        if ($signals['media_representative_command']) return $this->result(ContentIntent::MEDIA_ENRICHMENT, 'HEURISTIC', $signals);
        if ($signals['valid_video_url']) return $this->result(ContentIntent::VIDEO, 'HEURISTIC', $signals);
        if ($signals['has_assets'] && $signals['has_text']) return $this->result(ContentIntent::IMAGE_ARTICLE, 'HEURISTIC', $signals);
        if ($signals['atomic_delta']) return $this->result(ContentIntent::KNOWLEDGE_DELTA, 'HEURISTIC', $signals);
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
        $result['intent_reused'] = true;
        return $result;
    }

    /** @return array<string,mixed> */
    private function signals(array $input, array $interpretation, array $assets): array
    {
        $text = $this->editorialText($input);
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
        $mediaRepresentativeCommand = $this->isNaturalMediaRepresentativeCommand($text);
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
            'media_representative_command' => $mediaRepresentativeCommand,
            'sentence_count' => count($sentences),
            'independent_fact_count' => $candidateCount,
            'standalone_readability' => $text !== '' && (count($sentences) > 1 || $candidateCount > 1 || trim((string) ($input['title'] ?? '')) !== ''),
            'user_article_marker' => $userArticleMarker,
            'atomic_delta' => $atomicDelta,
            'asset_count' => count($assets),
        ];
    }

    /** @param array<string,mixed> $input */
    private function editorialText(array $input): string
    {
        foreach (['text', 'content', 'shared_description', 'description'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') return $value;
        }
        $media = is_array($input['media'] ?? null) ? $input['media'] : [];
        return trim((string) ($media['description'] ?? ''));
    }

    private function isNaturalMediaRepresentativeCommand(string $text): bool
    {
        foreach ([
            '~^Ảnh\s+đại\s+diện\s+của\s+https://\S+\s+thay\s+bằng\s+https://\S+\s*[.!?]?$~iu',
            '~^Thay\s+ảnh\s+đại\s+diện\s+của\s+https://\S+\s+bằng\s+https://\S+\s*[.!?]?$~iu',
            '~^Dùng\s+https://\S+\s+làm\s+ảnh\s+đại\s+diện\s+cho\s+https://\S+\s*[.!?]?$~iu',
            '~^Dùng\s+ảnh\s+https://\S+\s+làm\s+đại\s+diện\s+cho\s+https://\S+\s*[.!?]?$~iu',
        ] as $pattern) {
            if (preg_match($pattern, $text) === 1) return true;
        }
        return false;
    }

    private function assertExplicitIntentIsValid(ContentIntent $intent, array $input, array $assets): void
    {
        if ($intent === ContentIntent::KNOWLEDGE_REPAIR) {
            KnowledgeRepairIntent::fromArray(is_array($input['knowledge_repair'] ?? null) ? $input['knowledge_repair'] : []);
            if ($assets !== []) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_ASSETS_FORBIDDEN');
            if (($input['subject_hints'] ?? []) !== []) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_SUBJECT_INFERENCE_FORBIDDEN');
        }
        if ($intent === ContentIntent::VIDEO) {
            try {
                YouTubeUrlNormalizer::normalize(trim((string) ($input['video']['url'] ?? '')));
            } catch (\Throwable) {
                throw new \InvalidArgumentException('VIDEO_INTENT_REQUIRES_VALID_YOUTUBE_URL');
            }
        }
        if ($intent === ContentIntent::IMAGE_ARTICLE && $assets === []) {
            throw new \InvalidArgumentException('IMAGE_ARTICLE_REQUIRES_IMAGE');
        }
        if ($intent === ContentIntent::MEDIA_ENRICHMENT && $assets === [] && !$this->hasExistingMediaOperation($input)) {
            throw new \InvalidArgumentException('MEDIA_ENRICHMENT_REQUIRES_IMAGE');
        }
        if ($intent->requiresArticle() && $this->editorialText($input) === '' && trim((string) ($input['title'] ?? '')) === '') {
            throw new \InvalidArgumentException('ARTICLE_INTENT_REQUIRES_EDITORIAL_CONTENT');
        }
    }

    /** @param array<string,mixed> $input */
    private function hasExistingMediaOperation(array $input): bool
    {
        foreach ((array) ($input['media_operations'] ?? []) as $operation) {
            if (!is_array($operation)) continue;
            $kind = strtolower(trim((string) ($operation['operation'] ?? '')));
            if (!in_array($kind, ['update', 'add', 'replace', 'remove', 'keep', 'representative_bind'], true)) continue;

            $target = is_array($operation['target'] ?? null) ? $operation['target'] : [];
            if ($kind === 'remove') {
                if (trim((string) ($operation['usage_id'] ?? '')) !== '' && trim((string) ($target['type'] ?? '')) !== '') return true;
                continue;
            }

            $reference = is_array($operation['media'] ?? null)
                ? $operation['media']
                : (is_array($operation['media_ref'] ?? null) ? $operation['media_ref'] : []);
            if ($this->hasExistingMediaReference($reference)) return true;
        }

        foreach ((array) ($input['media_bindings'] ?? []) as $binding) {
            if (!is_array($binding)) continue;
            $reference = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
            if ($this->hasExistingMediaReference($reference)) return true;
        }

        return false;
    }

    /** @param array<string,mixed> $reference */
    private function hasExistingMediaReference(array $reference): bool
    {
        foreach (['id', 'media_id', 'stable_key', 'url'] as $key) {
            if (trim((string) ($reference[$key] ?? '')) !== '') return true;
        }
        return (int) ($reference['attachment_id'] ?? 0) > 0;
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
            'semantic_delta' => ['status' => in_array($intent, [ContentIntent::KNOWLEDGE_DELTA, ContentIntent::KNOWLEDGE_REPAIR], true) ? 'REQUIRED' : 'NONE'],
            'diagnostics' => [],
            'signals' => $signals,
        ];
    }
}
