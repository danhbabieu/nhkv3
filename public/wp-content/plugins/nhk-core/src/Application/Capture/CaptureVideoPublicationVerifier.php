<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\PublicIdentity\{CanonicalPublicSlugPolicy, PublicIdentityReadRegistry, PublicIdentityService};
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\{Video, VideoEditorialEnrichmentContext};
use NHK\Core\Application\Video\VideoEditorialQualityPolicy;

/** Verifies a Capture Video independently from the companion Article gate. */
final class CaptureVideoPublicationVerifier
{
    public function __construct(
        private VideoRepository $videos,
        private PublicIdentityService $identities,
        private PublicIdentityRepository $identityRepository,
        private ?CanonicalDependencyValidator $dependencies = null,
        /** @var callable(string,string,string):bool|null */
        private $aboutReadback = null,
        /** @var callable(string,string):bool|null */
        private $frontendReadback = null,
        ?CompletionCoordinator $completion = null,
        private ?VideoEditorialQualityPolicy $editorialQuality = null,
    ) {
        PublicIdentityReadRegistry::register($identityRepository);
        $this->completion = $completion ?? new CompletionCoordinator();
    }

    private CompletionCoordinator $completion;

    /** @return array<string,mixed> */
    public function verify(array $context): array
    {
        $items = [];
        $blockers = [];
        foreach ((array) ($context['assets'] ?? []) as $asset) {
            if (!is_array($asset) || ($asset['kind'] ?? '') !== 'video') continue;
            $proposal = is_array($asset['video_proposal'] ?? null) ? $asset['video_proposal'] : [];
            $payload = is_array($proposal['payload'] ?? null) ? $proposal['payload'] : [];
            $videoId = trim((string) ($asset['video_id'] ?? $payload['canonical_id'] ?? ''));
            $video = $videoId !== '' ? $this->videos->findByCanonicalId($videoId) : null;
            if (!$video instanceof Video) {
                $blockers[] = 'VIDEO_CANONICAL_READBACK_UNAVAILABLE';
                continue;
            }
            $source = is_array($payload['metadata']['source'] ?? null) ? $payload['metadata']['source'] : (is_array($payload['metadata']['source_snapshot'] ?? null) ? $payload['metadata']['source_snapshot'] : []);
            $expectedPlatform = trim((string) ($asset['platform'] ?? $source['platform'] ?? ''));
            $expectedExternalId = trim((string) ($asset['external_id'] ?? $asset['external_video_id'] ?? $source['external_video_id'] ?? ''));
            if (($expectedPlatform !== '' && $expectedPlatform !== $video->platform) || ($expectedExternalId !== '' && $expectedExternalId !== $video->externalVideoId)) {
                $blockers[] = 'VIDEO_EXTERNAL_IDENTITY_READBACK_INVALID';
                continue;
            }
            if ($expectedPlatform !== '' && $expectedExternalId !== '') {
                $exact = $this->videos->findByExternalReference($expectedPlatform, $expectedExternalId);
                if (!$exact instanceof Video || $exact->canonicalId !== $video->canonicalId) {
                    $blockers[] = 'VIDEO_EXTERNAL_IDENTITY_NOT_RESOLVABLE';
                    continue;
                }
            }
            $metadata = is_array($video->metadata) ? $video->metadata : [];
            $editorialContext = VideoEditorialEnrichmentContext::fromArray(is_array($metadata['enrichment_context'] ?? null) ? $metadata['enrichment_context'] : []);
            $contentQuality = ($this->editorialQuality ?? new VideoEditorialQualityPolicy())->evaluate(is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [], $editorialContext);
            if (!$contentQuality->complete()) {
                $blockers[] = 'CONTENT_NEEDS_REVIEW';
                continue;
            }
            $attachments = is_array($metadata['semantic_attachments'] ?? null) ? $metadata['semantic_attachments'] : [];
            if ($attachments === []) {
                $blockers[] = 'NO_SEMANTIC_ATTACHMENT';
                continue;
            }
            $videoRelationsValid = true;
            foreach ($attachments as $attachment) {
                if (!is_array($attachment) || ($attachment['predicate'] ?? '') !== 'about' || trim((string) ($attachment['target_uuid'] ?? '')) === '') {
                    $blockers[] = 'VIDEO_ABOUT_RELATION_READBACK_INVALID';
                    $videoRelationsValid = false;
                    continue;
                }
                $refs = is_array($attachment['evidence_refs'] ?? null) ? $attachment['evidence_refs'] : [];
                if ($refs === []) {
                    $blockers[] = 'VIDEO_ABOUT_EVIDENCE_MISSING';
                    $videoRelationsValid = false;
                    continue;
                }
                foreach ($refs as $ref) {
                    $evidenceId = is_array($ref) ? trim((string) ($ref['evidence_id'] ?? '')) : '';
                    if ($evidenceId === '') {
                        $blockers[] = 'VIDEO_ABOUT_EVIDENCE_INVALID';
                        $videoRelationsValid = false;
                        continue;
                    }
                    try { $this->dependencies?->evidence($evidenceId); } catch (\Throwable) { $blockers[] = 'VIDEO_ABOUT_EVIDENCE_READBACK_INVALID'; $videoRelationsValid = false; }
                }
                if (is_callable($this->aboutReadback)) {
                    try {
                        if (!(bool) ($this->aboutReadback)($video->canonicalId, (string) $attachment['target_type'], (string) $attachment['target_uuid'])) {
                            $blockers[] = 'VIDEO_ABOUT_RELATION_READBACK_MISSING';
                            $videoRelationsValid = false;
                        }
                    } catch (\Throwable) {
                        $blockers[] = 'VIDEO_ABOUT_RELATION_READBACK_UNAVAILABLE';
                        $videoRelationsValid = false;
                    }
                }
            }
            if (!$videoRelationsValid) continue;
            if (!$video->active || !$video->hasValidPublicReference()) {
                $blockers[] = 'VIDEO_CANONICAL_NOT_PUBLIC_READY';
                continue;
            }
            $identity = $this->identityRepository->findCurrentByOwner('video', $video->canonicalId, 'video');
            $operation = (string) ($proposal['operation'] ?? '');
            if ($identity === null && $operation === 'ingest') {
                $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
                $name = trim((string) ($editorial['title'] ?? $video->title));
                if ($name === '') {
                    $blockers[] = 'PUBLIC_IDENTITY_INPUT_INVALID';
                    continue;
                }
                $hub = is_array($metadata['hub'] ?? ($metadata['category'] ?? null)) ? ($metadata['hub'] ?? $metadata['category']) : [];
                $primary = is_array($hub['primary'] ?? null) ? ($hub['primary']['label'] ?? $hub['primary']['key'] ?? '') : (string) ($hub['primary'] ?? '');
                $this->identities->allocateCanonical('video', $video->canonicalId, 'video', 'root', $name, array_values(array_filter([(string) $primary])), (string) ($context['capture_id'] ?? '') . ':video:public-identity');
                $identity = $this->identityRepository->findCurrentByOwner('video', $video->canonicalId, 'video');
            }
            if (!is_array($identity) || !CanonicalPublicSlugPolicy::isCanonical((string) ($identity['current_slug'] ?? '')) || (string) ($identity['current_path'] ?? '') !== '/video/' . (string) $identity['current_slug'] . '/') {
                $blockers[] = 'PUBLIC_IDENTITY_NOT_PERSISTED';
                continue;
            }
            $path = (string) ($identity['current_path'] ?? '');
            $frontendVerified = null;
            if (is_callable($this->frontendReadback)) {
                try { $frontendVerified = (bool) ($this->frontendReadback)($video->canonicalId, $path); }
                catch (\Throwable) { $frontendVerified = false; }
                if ($frontendVerified !== true) $blockers[] = 'VIDEO_FRONTEND_READBACK_FAILED';
            }
            $completion = $this->completion->finalize('video', $video->canonicalId, [
                'canonical_readback' => ['canonical_id' => $video->canonicalId, 'platform' => $video->platform, 'external_id' => $video->externalVideoId],
                'dependency_state' => 'COMPLETE',
                'relation_or_usage_state' => 'COMPLETE',
                'public_eligible' => true,
                'frontend_verified' => $frontendVerified,
                'content_quality' => $contentQuality->status,
                'blockers' => $frontendVerified === false ? ['VIDEO_FRONTEND_READBACK_FAILED'] : [],
            ]);
            $items[] = ['video_id' => $video->canonicalId, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'external_video_id' => $video->externalVideoId, 'status' => 'verified', 'completion' => $completion, 'public_identity' => ['identity_id' => $identity['identity_id'] ?? null, 'slug' => $identity['current_slug'], 'path' => $path]];
        }
        if ($items === [] && $blockers === []) return ['status' => 'not_requested', 'items' => [], 'blockers' => [], 'completion' => $this->completion->finalize('video', '', ['canonical_state' => 'BLOCKED', 'blockers' => ['VIDEO_NOT_REQUESTED']])];
        $completion = count($items) === 1
            ? $items[0]['completion']
            : $this->completion->finalize('video', '', ['canonical_state' => 'BLOCKED', 'blockers' => $blockers !== [] ? $blockers : ['VIDEO_FRONTEND_READBACK_REQUIRED']]);
        return ['status' => $blockers === [] ? 'verified' : 'REVIEW_REQUIRED', 'items' => $items, 'blockers' => array_values(array_unique($blockers)), 'completion' => $completion];
    }
}
