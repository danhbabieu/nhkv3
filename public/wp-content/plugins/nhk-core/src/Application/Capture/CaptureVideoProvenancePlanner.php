<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Governance\CommandCanonicalizer;

/**
 * Plans the source-specific provenance dependencies for a Capture-owned Video.
 * This class is deliberately a planner: it has no repository or semantic
 * writer and cannot turn a subject identifier into Evidence by itself.
 */
final class CaptureVideoProvenancePlanner
{
    /** @return array<string,mixed> */
    public function plan(string $captureId, array $videoProposal, array $sourceSnapshot, array $resolvedSubject, array $context = []): array
    {
        $video = is_array($videoProposal['payload'] ?? null) ? $videoProposal : ['payload' => $videoProposal];
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $sourceTitle = trim((string) ($sourceSnapshot['source_title'] ?? $sourceSnapshot['title'] ?? ''));
        $subjectId = trim((string) ($resolvedSubject['id'] ?? ''));
        $subjectType = trim((string) ($resolvedSubject['type'] ?? ''));
        $subjectName = trim((string) ($resolvedSubject['name'] ?? ''));
        $externalId = trim((string) ($sourceSnapshot['external_video_id'] ?? $metadata['source']['external_video_id'] ?? ''));
        $platform = strtolower(trim((string) ($sourceSnapshot['platform'] ?? $metadata['source']['platform'] ?? 'youtube')));
        $locator = trim((string) ($sourceSnapshot['canonical_source_url'] ?? $payload['url'] ?? ''));
        $unsupported = $this->unsupportedClassifications($sourceTitle, (string) ($context['user_hint'] ?? ''));

        $base = [$platform, $externalId, $locator, $subjectType, $subjectId];
        $sourceKey = 'nhk:source:video:' . hash('sha256', CommandCanonicalizer::canonicalize([$platform, $externalId, $locator]));
        $claimKey = 'nhk:knowledge:video-provenance:' . hash('sha256', CommandCanonicalizer::canonicalize($base));
        $evidenceKey = 'video-provenance:evidence:' . hash('sha256', CommandCanonicalizer::canonicalize([$sourceKey, $claimKey]));
        $emptyVideo = $this->withAttachments($video, []);

        if ($sourceTitle === '' || $subjectId === '' || $subjectType === '' || !$this->titleIdentifiesSubject($sourceTitle, $resolvedSubject)) {
            return [
                'status' => 'REVIEW_REQUIRED',
                'blockers' => ['SOURCE_SUBJECT_IDENTITY_UNCONFIRMED'],
                'dependencies' => [],
                'video_proposal' => $emptyVideo,
                'evidence_idempotency_key' => $evidenceKey,
                'reuse_scope' => 'source-specific-external-video',
                'unsupported_classifications' => $unsupported,
                'diagnostics' => ['source_title' => $sourceTitle, 'subject_id' => $subjectId, 'subject_type' => $subjectType],
            ];
        }

        $identity = 'canonical ' . ($subjectName !== '' ? $subjectName : $subjectId);
        $sourcePayload = [
            'stable_key' => $sourceKey,
            'title' => $sourceTitle,
            'source_type' => 'website',
            'locator' => $locator !== '' ? $locator : null,
            'metadata' => [
                'visibility' => 'PRIVATE',
                'origin' => 'CAPTURE_VIDEO_SOURCE_SNAPSHOT',
                'platform' => $platform,
                'external_video_id' => $externalId,
            ],
        ];
        $claimPayload = [
            'stable_key' => $claimKey,
            'text' => 'The source identifies this Video as concerning ' . $identity . '.',
            'claim_type' => 'provenance',
            'provenance' => [
                'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
                'metadata' => [
                    'verification_status' => 'PRIVATE',
                    'knowledge_status' => 'PRIVATE',
                    'subject_id' => $subjectId,
                    'subject_type' => $subjectType,
                    'platform' => $platform,
                    'external_video_id' => $externalId,
                    'source_stable_key' => $sourceKey,
                ],
            ],
        ];
        $evidencePayload = [
            'claim_id' => null,
            'source_id' => null,
            'excerpt' => $sourceTitle,
            'relation' => 'supports',
            'locator' => $locator !== '' ? $locator : null,
            'metadata' => [
                'visibility' => 'PRIVATE',
                'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
                'platform' => $platform,
                'external_video_id' => $externalId,
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
                'source_stable_key' => $sourceKey,
                'claim_stable_key' => $claimKey,
            ],
        ];

        return [
            'status' => 'READY',
            'blockers' => [],
            'dependencies' => [
                $this->arguments('source', $sourceKey, $sourcePayload, 'video-provenance:source:' . hash('sha256', $sourceKey)),
                $this->arguments('knowledge', $subjectId, $claimPayload, 'video-provenance:claim:' . hash('sha256', $claimKey)),
            ],
            'evidence' => $evidencePayload,
            'source_stable_key' => $sourceKey,
            'claim_stable_key' => $claimKey,
            'evidence_idempotency_key' => $evidenceKey,
            'reuse_scope' => 'source-specific-external-video',
            'unsupported_classifications' => $unsupported,
            'video_proposal' => $emptyVideo,
            'relation' => ['target_type' => $subjectType, 'target_uuid' => $subjectId, 'predicate' => 'about'],
            'diagnostics' => ['source_title' => $sourceTitle, 'subject_id' => $subjectId, 'subject_type' => $subjectType],
        ];
    }

    /** @return array<string,mixed> */
    public function attachEvidence(array $plan, string $sourceId, string $claimId, string $evidenceId): array
    {
        if (($plan['status'] ?? '') !== 'READY') return $plan;
        $evidence = is_array($plan['evidence'] ?? null) ? $plan['evidence'] : [];
        $evidence['source_id'] = $sourceId;
        $evidence['claim_id'] = $claimId;
        $dependencies = (array) ($plan['dependencies'] ?? []);
        $dependencies[] = $this->arguments('evidence', $claimId, $evidence, (string) ($plan['evidence_idempotency_key'] ?? ''));
        $relation = is_array($plan['relation'] ?? null) ? $plan['relation'] : [];
        $attachment = [
            'target_type' => (string) ($relation['target_type'] ?? ''),
            'target_uuid' => (string) ($relation['target_uuid'] ?? ''),
            'predicate' => (string) ($relation['predicate'] ?? 'about'),
            'evidence_refs' => [['evidence_id' => $evidenceId]],
        ];
        $plan['dependencies'] = $dependencies;
        $plan['video_proposal'] = $this->withAttachments((array) ($plan['video_proposal'] ?? []), [$attachment]);
        $plan['evidence'] = $evidence;
        $plan['evidence_id'] = $evidenceId;
        return $plan;
    }

    /** @return array<string,mixed> */
    private function arguments(string $entityType, string $subjectId, array $payload, string $idempotencyKey): array
    {
        return ['operation' => 'ingest', 'entity_type' => $entityType, 'subject_id' => $subjectId, 'payload' => $payload, 'idempotency_key' => $idempotencyKey];
    }

    /** @param array<string,mixed> $video @return array<string,mixed> */
    private function withAttachments(array $video, array $attachments): array
    {
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $metadata['semantic_attachments'] = $attachments;
        $payload['metadata'] = $metadata;
        $video['payload'] = $payload;
        return $video;
    }

    private function titleIdentifiesSubject(string $title, array $subject): bool
    {
        $title = $this->normalize($title);
        if ($title === '') return false;
        if (($subject['type'] ?? '') === 'variant' && $this->variantReferenceIdentifies($title, $subject)) return true;
        $terms = array_merge(
            [(string) ($subject['name'] ?? '')],
            array_map('strval', (array) ($subject['aliases'] ?? [])),
            $this->stableKeyIdentityTerms($subject),
        );
        foreach ($terms as $term) {
            $term = $this->normalize($term);
            if ($term !== '' && str_contains($title, $term)) return true;
        }
        return false;
    }

    private function variantReferenceIdentifies(string $title, array $subject): bool
    {
        $reference = (string) ($subject['reference'] ?? '');
        if ($reference === '' && preg_match('/\b\d+\s*\/\s*\d+\b/u', (string) ($subject['name'] ?? ''), $match) === 1) $reference = $match[0];
        $reference = $this->normalize($reference);
        return $reference !== '' && str_contains($title, $reference);
    }

    /** @return list<string> */
    private function stableKeyIdentityTerms(array $subject): array
    {
        if (($subject['type'] ?? '') !== 'variant') return [];
        $stableKey = trim((string) ($subject['stable_key'] ?? ''));
        if ($stableKey === '') return [];
        $parts = explode(':', $stableKey);
        $term = trim((string) end($parts));
        return $term === '' ? [] : [$term];
    }

    /** @return list<string> */
    private function unsupportedClassifications(string $sourceTitle, string $userHint): array
    {
        $text = $this->normalize($sourceTitle . ' ' . $userHint);
        $result = [];
        if (str_contains($text, 'con dong')) $result[] = 'Côn đồng';
        if (str_contains($text, 'con thep')) $result[] = 'Côn thép';
        return $result;
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        if (function_exists('remove_accents')) $value = (string) remove_accents($value);
        elseif (function_exists('transliterator_transliterate')) $value = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        elseif (function_exists('iconv')) $value = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $value));
    }
}
