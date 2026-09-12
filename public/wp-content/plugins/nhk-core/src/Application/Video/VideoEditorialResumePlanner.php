<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Domain\Video\Video;

/** Builds a governed update for an existing Video without changing identity. */
final class VideoEditorialResumePlanner
{
    public const POLICY_VERSION = 'video-editorial-resume-1';

    public function __construct(
        private VideoRepository $videos,
        private VideoEditorialGenerator $editorial,
        private VideoSeoProjection $seo,
        private ?PublicEditorialCopyGuard $publicCopyGuard = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function plan(array $videoProposal, array $context): array
    {
        $payload = is_array($videoProposal['payload'] ?? null) ? $videoProposal['payload'] : [];
        $videoId = trim((string) ($payload['canonical_id'] ?? $videoProposal['target_uuid'] ?? $videoProposal['subject_id'] ?? ''));
        $video = $videoId !== '' ? $this->videos->findByCanonicalId($videoId) : null;
        if (!$video instanceof Video) throw new \RuntimeException('VIDEO_CANONICAL_READBACK_UNAVAILABLE');

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        $source = array_merge($source, [
            'platform' => $video->platform,
            'external_video_id' => $video->externalVideoId,
            'canonical_source_url' => $video->canonicalUrl,
        ]);
        $subject = $this->subject($context, $metadata);
        $claims = $this->claims($context);
        $delta = trim((string) ($context['continuation_delta_text'] ?? ''));
        $fingerprint = $this->fingerprint($video, $source, $subject, $claims, $delta);
        $existingFingerprint = trim((string) ($metadata['editorial_input_fingerprint'] ?? ''));
        if ($existingFingerprint !== '' && hash_equals($existingFingerprint, $fingerprint)) return $this->reuse($video, $fingerprint);
        // Legacy Videos may not carry a fingerprint. An explicit resume with
        // no editorial input is still a no-op; do not mint an unnecessary
        // revision merely to add bookkeeping.
        if ($existingFingerprint === '' && $delta === '') return $this->reuse($video, $fingerprint);

        $enrichment = [
            'source_facts' => trim((string) ($source['source_title'] ?? '')) !== '' ? [['text' => (string) $source['source_title']]] : [],
            'canonical_context' => $subject === null ? [] : [['text' => (string) ($subject['name'] ?? ''), 'entity_id' => (string) ($subject['id'] ?? ''), 'entity_type' => (string) ($subject['type'] ?? '')]],
        ];
        $editorial = $this->editorial->generate($source, $delta, '', $subject, '', '', $enrichment);
        ($this->publicCopyGuard ?? new PublicEditorialCopyGuard())->assertEditorialPackage($editorial);
        $seo = ['title' => (string) ($editorial['title'] ?? ''), 'description' => (string) ($editorial['summary'] ?? '')];
        $package = [
            'source' => $source,
            'editorial' => $editorial,
            'seo' => $seo,
            'subject_resolution_packet' => $subject,
        ];
        $metadata['editorial'] = $editorial;
        $metadata['seo'] = $seo;
        $metadata['enrichment_context'] = $enrichment;
        $metadata['seo_projection'] = $this->seo->project($package, $video->canonicalUrl);
        $metadata['editorial_input_fingerprint'] = $fingerprint;
        $metadata['editorial_reconciliation'] = [
            'status' => 'REBUILD_EDITORIAL',
            'fingerprint' => $fingerprint,
            'policy_version' => self::POLICY_VERSION,
        ];

        return [
            'status' => 'REBUILD_EDITORIAL',
            'operation' => 'update',
            'entity_type' => 'video',
            'subject_id' => $video->canonicalId,
            'target_uuid' => $video->canonicalId,
            'expected_revision' => $video->revision,
            'idempotency_key' => 'capture:' . (string) ($context['capture_id'] ?? $video->canonicalId) . ':video-editorial:' . $fingerprint,
            'fingerprint' => $fingerprint,
            'payload' => [
                'canonical_id' => $video->canonicalId,
                'url' => $video->canonicalUrl,
                // Video.title remains the owner fallback identity label;
                // public editorial title lives in metadata.editorial.title.
                'title' => $video->title,
                'metadata' => $metadata,
                'thumbnail_media_id' => $video->thumbnailMediaId ?? '',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function reuse(Video $video, string $fingerprint): array
    {
        return [
            'status' => 'REUSE_EDITORIAL',
            'operation' => 'update',
            'entity_type' => 'video',
            'subject_id' => $video->canonicalId,
            'target_uuid' => $video->canonicalId,
            'fingerprint' => $fingerprint,
            'canonical_readback' => [
                'canonical_id' => $video->canonicalId,
                'platform' => $video->platform,
                'external_video_id' => $video->externalVideoId,
                'revision' => $video->revision,
                'editorial_input_fingerprint' => $video->metadata['editorial_input_fingerprint'] ?? null,
            ],
            'idempotent' => true,
        ];
    }

    /** @return array<string,mixed>|null */
    private function subject(array $context, array $metadata): ?array
    {
        $subject = is_array($context['subject_resolution']['primary'] ?? null) ? $context['subject_resolution']['primary'] : [];
        if ($subject === []) $subject = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];
        return $subject === [] ? null : [
            'id' => (string) ($subject['id'] ?? ''),
            'type' => (string) ($subject['type'] ?? ''),
            'name' => (string) ($subject['name'] ?? ''),
            'revision' => isset($subject['revision']) ? (int) $subject['revision'] : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function claims(array $context): array
    {
        $claims = [];
        foreach ((array) ($context['retrieval']['selected_claims'] ?? []) as $claim) {
            if (!is_array($claim)) continue;
            $id = trim((string) ($claim['id'] ?? $claim['claim_id'] ?? $claim['canonical_id'] ?? ''));
            if ($id === '') continue;
            $claims[] = ['id' => $id, 'revision' => (int) ($claim['revision'] ?? $claim['claim_revision'] ?? 0)];
        }
        usort($claims, static fn (array $left, array $right): int => strcmp(CommandCanonicalizer::canonicalize($left), CommandCanonicalizer::canonicalize($right)));
        return $claims;
    }

    private function fingerprint(Video $video, array $source, ?array $subject, array $claims, string $delta): string
    {
        $sourceState = [
            'platform' => $video->platform,
            'external_video_id' => $video->externalVideoId,
            'canonical_source_url' => $video->canonicalUrl,
            'source_revision' => $source['source_revision'] ?? $source['revision'] ?? null,
            'source_snapshot_hash' => $source['source_snapshot_hash'] ?? null,
            'source_title' => $source['source_title'] ?? $source['title'] ?? null,
            'source_description' => $source['source_description'] ?? $source['description'] ?? null,
        ];
        return hash('sha256', CommandCanonicalizer::canonicalize([
            'source' => $sourceState,
            'user_editorial_delta' => $delta,
            'subject' => $subject,
            'claims' => $claims,
            'policy_version' => self::POLICY_VERSION,
        ]));
    }
}
