<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, SourceRepository};
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;

final class VideoUrlPolicy
{
    public function __construct(private ?PublicIdentityRepository $identities = null, private ?AuthorityRepository $authority = null, private ?EntityTypeRegistry $entityTypes = null, private ?EvidenceRepository $evidence = null, private ?SourceRepository $sources = null, private ?PredicateRegistry $predicates = null)
    {
    }

    /** @return array{path:?string,eligible:bool,blockers:list<string>,warnings:list<string>} */
    public function project(Video $video, VideoPublicContextSelector $selector): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $blockers = [];
        $identity = null;
        if ($this->identities === null) $blockers[] = 'PUBLIC_IDENTITY_UNAVAILABLE';
        else {
            try { $identity = $this->identities->findByOwner('video', $video->canonicalId); } catch (\Throwable) { $identity = null; }
            if ($identity === null) $blockers[] = 'PUBLIC_IDENTITY_NOT_FOUND';
            elseif (!UuidCodec::isValid($identity->ownerId) || $identity->ownerKind !== 'video' || $identity->ownerId !== $video->canonicalId || $identity->routeType !== 'video' || $identity->collisionScope !== 'video' || $identity->routePolicyVersion !== 'public-route-v1' || $identity->revision < 1) $blockers[] = 'PUBLIC_IDENTITY_INVALID';
        }
        $slug = $identity?->currentSlug ?? '';
        if ($video->platform !== 'youtube' || preg_match('/^[A-Za-z0-9_-]{11}$/', $video->externalVideoId) !== 1 || !$video->hasValidPublicReference()) $blockers[] = 'SOURCE_IDENTITY_INVALID';
        if ($this->authority === null || $this->entityTypes === null || $this->evidence === null || $this->sources === null || $this->predicates === null) $blockers[] = 'GOVERNANCE_READ_BOUNDARY_UNAVAILABLE';

        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
        try {
            $snapshot = \NHK\Core\Domain\Video\YouTubeSourceSnapshot::fromArray($source);
            if ($snapshot->externalVideoId !== $video->externalVideoId || $snapshot->availability !== 'available') $blockers[] = 'SOURCE_UNAVAILABLE';
            if ($snapshot->embeddable !== true) $blockers[] = 'SOURCE_NOT_EMBEDDABLE';
        } catch (\Throwable) { $blockers[] = 'SOURCE_SNAPSHOT_INVALID'; }
        $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
        if (trim((string) ($editorial['title'] ?? '')) === '' || trim((string) ($editorial['summary'] ?? '')) === '') $blockers[] = 'EDITORIAL_CONTEXT_INCOMPLETE';
        $hub = is_array($metadata['hub'] ?? null) ? $metadata['hub'] : [];
        $hubPrimary = is_array($hub['primary'] ?? null) ? ($hub['primary']['key'] ?? $hub['primary']['label'] ?? '') : ($hub['primary'] ?? '');
        if (!array_key_exists((string) $hubPrimary, VideoHubClassifier::hubs())) $blockers[] = 'VIDEO_HUB_UNRESOLVED';
        $provenance = is_array($metadata['provenance'] ?? null) ? $metadata['provenance'] : [];
        if (($provenance['kind'] ?? '') !== 'YOUTUBE_SOURCE' || ($provenance['source_url'] ?? '') !== ($source['canonical_source_url'] ?? '')) $blockers[] = 'VIDEO_PROVENANCE_MISSING';
        if (!$this->hasApprovedAttachment($metadata['semantic_attachments'] ?? null)) $blockers[] = 'SEMANTIC_ATTACHMENT_UNUSABLE';

        $context = $this->context($metadata);
        if ($selector->select($context) === null && $slug === '') $blockers[] = 'GOVERNED_CONTEXT_MISSING';
        $eligible = $blockers === [];
        return [
            'path' => $eligible ? '/video/' . $slug . '-' . strtolower($video->externalVideoId) . '/' : null,
            'eligible' => $eligible,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => [],
        ];
    }

    private function hasApprovedAttachment(mixed $attachments): bool
    {
        if (!is_array($attachments)) return false;
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || ($attachment['approved'] ?? false) !== true || ($attachment['target_type'] ?? '') === '' || !$this->entityTypes?->has((string) $attachment['target_type']) || !UuidCodec::isValid((string) ($attachment['target_id'] ?? $attachment['target_key'] ?? ''))) continue;
            try {
                $targetId = (string) ($attachment['target_id'] ?? $attachment['target_key']);
                $target = $this->authority?->findByCanonicalId($targetId);
                $predicate = (string) ($attachment['predicate'] ?? '');
                $definition = $this->predicates?->get($predicate);
                if ($target === null || $target->canonicalId !== $targetId || !$target->active() || $target->entityType !== $attachment['target_type'] || $definition === null || !$definition->allows('video', (string) $attachment['target_type'])) continue;
            } catch (\Throwable) { continue; }
            $evidence = $attachment['evidence_refs'] ?? null;
            if (!is_array($evidence) || $evidence === []) continue;
            $valid = true;
            foreach ($evidence as $reference) {
                if (!is_array($reference) || array_keys($reference) !== ['evidence_id'] || !UuidCodec::isValid((string) $reference['evidence_id'])) { $valid = false; continue; }
                try {
                    $record = $this->evidence?->findByCanonicalId((string) $reference['evidence_id']);
                    if ($record === null || !$record->active || !$record->isPublic()) { $valid = false; continue; }
                    $source = $this->sources?->findByCanonicalId($record->sourceId);
                    if ($source === null || !$source->active || !$source->isPublic()) $valid = false;
                } catch (\Throwable) { $valid = false; }
            }
            if ($valid) return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function context(array $metadata): array
    {
        $context = is_array($metadata['governed_context'] ?? null) ? $metadata['governed_context'] : [];
        return $context;
    }
}
