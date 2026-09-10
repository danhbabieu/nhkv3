<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\PublicIdentity\{CanonicalPublicSlugPolicy, PublicIdentityReadRegistry, PublicIdentityService};
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\Video;

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
    ) {
        PublicIdentityReadRegistry::register($identityRepository);
    }

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
            $metadata = is_array($video->metadata) ? $video->metadata : [];
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
            $items[] = ['video_id' => $video->canonicalId, 'external_video_id' => $video->externalVideoId, 'status' => 'verified', 'public_identity' => ['identity_id' => $identity['identity_id'] ?? null, 'slug' => $identity['current_slug'], 'path' => $identity['current_path']]];
        }
        if ($items === [] && $blockers === []) return ['status' => 'not_requested', 'items' => [], 'blockers' => []];
        return ['status' => $blockers === [] ? 'verified' : 'REVIEW_REQUIRED', 'items' => $items, 'blockers' => array_values(array_unique($blockers))];
    }
}
