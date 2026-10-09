<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Read-only public eligibility boundary for persisted Video attachments.
 *
 * A non-empty metadata array is not proof of a governed relation. Public
 * surfaces require the canonical Video -> about -> target shape and exact
 * Evidence references. Canonical dependency validation is optional so the
 * policy remains usable by projection-only callers; the governed verifier
 * remains the authority when a dependency reader is available.
 */
final class VideoPublicAttachmentPolicy
{
    public function __construct(private ?CanonicalDependencyValidator $dependencies = null)
    {
    }

    /** @return list<string> */
    public function blockers(mixed $attachments): array
    {
        if (!is_array($attachments) || $attachments === []) return ['NO_SEMANTIC_ATTACHMENT'];

        $blockers = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)
                || strtolower(trim((string) ($attachment['predicate'] ?? ''))) !== 'about'
                || !UuidCodec::isValid(trim((string) ($attachment['target_uuid'] ?? '')))
                || trim((string) ($attachment['target_type'] ?? '')) === '') {
                $blockers[] = 'VIDEO_ABOUT_RELATION_READBACK_INVALID';
                continue;
            }

            $refs = $attachment['evidence_refs'] ?? null;
            if (!is_array($refs) || $refs === []) {
                $blockers[] = 'VIDEO_ABOUT_EVIDENCE_MISSING';
                continue;
            }
            foreach ($refs as $reference) {
                if (!is_array($reference)
                    || array_keys($reference) !== ['evidence_id']
                    || !UuidCodec::isValid(trim((string) ($reference['evidence_id'] ?? '')))) {
                    $blockers[] = 'VIDEO_ABOUT_EVIDENCE_INVALID';
                    continue;
                }
                if ($this->dependencies === null) continue;
                try {
                    $this->dependencies->evidence((string) $reference['evidence_id']);
                } catch (\Throwable) {
                    $blockers[] = 'VIDEO_ABOUT_EVIDENCE_READBACK_INVALID';
                }
            }
        }

        return array_values(array_unique($blockers));
    }
}
