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
        $proposalMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null;
        $previousSubjectId = trim((string) (($metadata['subject_resolution_packet']['id'] ?? '')));
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
        $desired = null;
        $staleEditorialReplay = false;
        if ($existingFingerprint !== '' && hash_equals($existingFingerprint, $fingerprint)) {
            $desired = $this->desiredPackage($video, $source, $subject, $delta, $proposalMetadata);
            if ($this->canonicalEditorialPayloadMatches($video, $desired, $fingerprint)) {
                return $this->reuse($video, $fingerprint, $desired);
            }
            $staleEditorialReplay = true;
        }
        // Legacy Videos may not carry a fingerprint. An explicit resume with
        // no editorial input is still a no-op; do not mint an unnecessary
        // revision merely to add bookkeeping.
        if ($existingFingerprint === '' && $delta === '') return $this->reuse($video, $fingerprint);

        $desired ??= $this->desiredPackage($video, $source, $subject, $delta);
        $enrichment = $desired['enrichment'];
        $editorial = $desired['editorial'];
        $seo = $desired['seo'];
        $package = $desired['package'];
        $metadata['editorial'] = $editorial;
        $metadata['seo'] = $seo;
        $metadata['subject_resolution_packet'] = $subject;
        if ($subject !== null && $previousSubjectId !== '' && $previousSubjectId !== (string) ($subject['id'] ?? '')) {
            // A subject correction cannot carry the old target forward by
            // accident. A replacement relation must arrive with fresh
            // governed evidence; otherwise completeness remains blocked.
            $metadata['semantic_attachments'] = array_values(array_filter(
                (array) ($metadata['semantic_attachments'] ?? []),
                static fn (mixed $attachment): bool => !is_array($attachment)
                    || strtolower(trim((string) ($attachment['target_uuid'] ?? $attachment['target_id'] ?? ''))) !== strtolower($previousSubjectId),
            ));
            $metadata['semantic_reconciliation_requested'] = true;
        }
        $metadata['enrichment_context'] = $enrichment;
        $metadata['seo_projection'] = $desired['seo_projection'];
        $metadata['editorial_input_fingerprint'] = $fingerprint;
        $metadata['editorial_reconciliation'] = [
            'status' => 'REBUILD_EDITORIAL',
            'fingerprint' => $fingerprint,
            'policy_version' => self::POLICY_VERSION,
        ] + ($staleEditorialReplay ? ['diagnostic' => 'STALE_EDITORIAL_REPLAY'] : []);

        return [
            'status' => 'REBUILD_EDITORIAL',
            'operation' => 'update',
            'entity_type' => 'video',
            'subject_id' => $video->canonicalId,
            'target_uuid' => $video->canonicalId,
            'expected_revision' => $video->revision,
            // The canonical revision is part of the governed command identity.
            // A prior proposal for the same semantic fingerprint may carry an
            // obsolete CAS revision; never let that proposal be reused for a
            // newer canonical read.
            'idempotency_key' => 'capture:' . (string) ($context['capture_id'] ?? $video->canonicalId) . ':video-editorial:revision:' . $video->revision . ':' . $fingerprint,
            'fingerprint' => $fingerprint,
            'payload' => [
                'canonical_id' => $video->canonicalId,
                'url' => $video->canonicalUrl,
                // Keep the canonical title aligned with the desired editorial
                // label; source-title provenance remains in metadata.source.
                'title' => (string) ($editorial['title'] ?? $video->title),
                'metadata' => $metadata,
                'thumbnail_media_id' => $video->thumbnailMediaId ?? '',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function reuse(Video $video, string $fingerprint, ?array $desired = null): array
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
                'title' => $video->title,
                'editorial_title' => $desired['editorial']['title'] ?? ($video->metadata['editorial']['title'] ?? $video->title),
                'platform' => $video->platform,
                'external_video_id' => $video->externalVideoId,
                'canonical_url' => $video->canonicalUrl,
                'revision' => $video->revision,
                'editorial_input_fingerprint' => $video->metadata['editorial_input_fingerprint'] ?? null,
                'editorial_payload_parity' => $desired !== null,
            ],
            'idempotent' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function desiredPackage(Video $video, array $source, ?array $subject, string $delta, ?array $proposalMetadata = null): array
    {
        $enrichment = [
            'source_facts' => trim((string) ($source['source_title'] ?? '')) !== '' ? [['text' => (string) $source['source_title']]] : [],
            'canonical_context' => $subject === null ? [] : [['text' => (string) ($subject['name'] ?? ''), 'entity_id' => (string) ($subject['id'] ?? ''), 'entity_type' => (string) ($subject['type'] ?? '')]],
        ];
        $replayEditorial = is_array($proposalMetadata['editorial'] ?? null) ? $proposalMetadata['editorial'] : [];
        $replayTitle = trim((string) ($replayEditorial['title'] ?? ''));
        $replayPackage = $delta === '' && $replayTitle !== '' ? $proposalMetadata : null;
        if ($replayPackage !== null) {
            $enrichment = is_array($replayPackage['enrichment_context'] ?? null) ? $replayPackage['enrichment_context'] : $enrichment;
            $editorial = $replayEditorial;
            $seo = is_array($replayPackage['seo'] ?? null) ? $replayPackage['seo'] : ['title' => (string) ($editorial['title'] ?? ''), 'description' => (string) ($editorial['summary'] ?? '')];
            $subject = is_array($replayPackage['subject_resolution_packet'] ?? null) ? $replayPackage['subject_resolution_packet'] : $subject;
            $package = $replayPackage;
            $package['source'] = is_array($package['source'] ?? null) ? $package['source'] : $source;
            $package['editorial'] = $editorial;
            $package['seo'] = $seo;
            $package['subject_resolution_packet'] = $subject;
        } else {
            $editorial = $this->editorial->generate($source, $delta, '', $subject, '', '', $enrichment);
            ($this->publicCopyGuard ?? new PublicEditorialCopyGuard())->assertEditorialPackage($editorial);
            $seo = ['title' => (string) ($editorial['title'] ?? ''), 'description' => (string) ($editorial['summary'] ?? '')];
            $package = [
                'source' => $source,
                'editorial' => $editorial,
                'seo' => $seo,
                'subject_resolution_packet' => $subject,
            ];
        }
        $package['canonical_id'] = $video->canonicalId;
        ($this->publicCopyGuard ?? new PublicEditorialCopyGuard())->assertEditorialPackage($editorial);
        $seoProjection = is_array($package['seo_projection'] ?? null)
            ? $package['seo_projection']
            : $this->seo->project($package, $video->canonicalUrl);

        return [
            'enrichment' => $enrichment,
            'editorial' => $editorial,
            'seo' => $seo,
            'package' => $package,
            'subject_resolution_packet' => $subject,
            'seo_projection' => $seoProjection,
        ];
    }

    private function canonicalEditorialPayloadMatches(Video $video, array $desired, string $fingerprint): bool
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $source = is_array($desired['package']['source'] ?? null) ? $desired['package']['source'] : [];
        $desiredTitle = trim((string) ($desired['editorial']['title'] ?? ''));
        $storedSource = is_array($metadata['source'] ?? null)
            ? $metadata['source']
            : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);

        return $video->canonicalId === (string) ($desired['package']['canonical_id'] ?? '')
            && $desiredTitle !== ''
            && $video->title === $desiredTitle
            && $video->platform === (string) ($source['platform'] ?? $video->platform)
            && $video->externalVideoId === (string) ($source['external_video_id'] ?? $video->externalVideoId)
            && $video->canonicalUrl === (string) ($source['canonical_source_url'] ?? $video->canonicalUrl)
            && ($storedSource['external_video_id'] ?? $video->externalVideoId) === $video->externalVideoId
            && ($storedSource['canonical_source_url'] ?? $video->canonicalUrl) === $video->canonicalUrl
            && ($metadata['editorial_input_fingerprint'] ?? null) === $fingerprint
            && $this->sameCanonicalValue($metadata['editorial'] ?? null, $desired['editorial'] ?? null)
            && $this->sameCanonicalValue($metadata['seo'] ?? null, $desired['seo'] ?? null)
            && $this->sameCanonicalValue($metadata['subject_resolution_packet'] ?? null, $desired['subject_resolution_packet'] ?? null)
            && $this->sameCanonicalValue($metadata['seo_projection'] ?? null, $desired['seo_projection'] ?? null)
            && $this->semanticTargetParity($metadata['semantic_attachments'] ?? null, $desired['package']['semantic_attachments'] ?? null);
    }

    private function sameCanonicalValue(mixed $actual, mixed $desired): bool
    {
        if (!is_array($actual) || !is_array($desired)) return $actual === $desired;
        return CommandCanonicalizer::canonicalize($actual) === CommandCanonicalizer::canonicalize($desired);
    }

    private function semanticTargetParity(mixed $actual, mixed $desired): bool
    {
        if (!is_array($desired)) return true;
        $actualTargets = $this->semanticTargets($actual);
        $desiredTargets = $this->semanticTargets($desired);
        sort($actualTargets);
        sort($desiredTargets);
        return $actualTargets === $desiredTargets;
    }

    /** @return list<string> */
    private function semanticTargets(mixed $attachments): array
    {
        $targets = [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            if (!is_array($attachment)) continue;
            $target = trim((string) ($attachment['target_uuid'] ?? $attachment['target_id'] ?? ''));
            if ($target === '') continue;
            $targets[] = strtolower((string) ($attachment['target_type'] ?? '') . ':' . $target);
        }
        return array_values(array_unique($targets));
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
