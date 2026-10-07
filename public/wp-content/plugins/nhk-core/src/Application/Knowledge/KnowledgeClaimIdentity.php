<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Video\VideoIdentityReader;
use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Knowledge-owner identity context; Source is support, not Claim identity. */
final class KnowledgeClaimIdentity
{
    public static function resolveInput(string $claimType, array $provenance, ?VideoIdentityReader $videos = null): KnowledgeClaimIdentityResolution
    {
        $metadata = is_array($provenance['metadata'] ?? null) ? $provenance['metadata'] : [];
        $origin = strtoupper(trim((string) ($provenance['origin'] ?? $metadata['origin'] ?? '')));
        if ($origin === 'CAPTURE_VIDEO_SOURCE_PROVENANCE') return self::resolveVideo($metadata, $videos);

        $packet = [
            'subject_id' => trim((string) ($metadata['subject_id'] ?? $metadata['canonical_subject_id'] ?? '')),
            'facet' => trim((string) ($metadata['facet'] ?? '')),
            'scope' => trim((string) ($metadata['scope'] ?? '')),
            'claim_type' => trim($claimType),
            'proposition' => trim((string) ($metadata['proposition'] ?? $metadata['deterministic_proposition'] ?? '')),
        ];
        $missing = array_keys(array_filter($packet, static fn (mixed $value): bool => trim((string) $value) === ''));
        if ($missing !== []) return new KnowledgeClaimIdentityResolution(KnowledgeClaimIdentityResolution::UNRESOLVED, $packet, ['KNOWLEDGE_IDENTITY_REQUIRED_FIELD_MISSING']);
        return new KnowledgeClaimIdentityResolution(KnowledgeClaimIdentityResolution::RESOLVED, $packet);
    }

    public static function resolveClaim(KnowledgeClaim $claim, ?VideoIdentityReader $videos = null): KnowledgeClaimIdentityResolution
    {
        $provenance = $claim->provenance;
        $provenance['metadata'] = array_merge((array) ($provenance['metadata'] ?? []), ['proposition' => $claim->claimText]);
        return self::resolveInput($claim->claimType, $provenance, $videos);
    }

    public static function resolveAuditRow(array $row, ?VideoIdentityReader $videos = null): KnowledgeClaimIdentityResolution
    {
        $provenance = is_array($row['provenance'] ?? null) ? $row['provenance'] : [];
        $metadata = is_array($provenance['metadata'] ?? null) ? $provenance['metadata'] : [];
        foreach (['subject_id', 'canonical_subject_id', 'facet', 'scope', 'platform', 'external_video_id', 'canonical_video_id', 'video_id', 'origin', 'proposition', 'deterministic_proposition', 'proposition_class'] as $key) if (array_key_exists($key, $row) && !array_key_exists($key, $metadata)) $metadata[$key] = $row[$key];
        $provenance['origin'] = $provenance['origin'] ?? $metadata['origin'] ?? null;
        if (!isset($metadata['proposition']) && isset($row['claim_text'])) $metadata['proposition'] = (string) $row['claim_text'];
        $provenance['metadata'] = $metadata;
        return self::resolveInput((string) ($row['claim_type'] ?? $metadata['claim_type'] ?? 'fact'), $provenance, $videos);
    }

    /** @param array<string,mixed> $metadata */
    private static function resolveVideo(array $metadata, ?VideoIdentityReader $videos): KnowledgeClaimIdentityResolution
    {
        $subjectId = trim((string) ($metadata['subject_id'] ?? $metadata['canonical_subject_id'] ?? ''));
        $propositionClass = trim((string) ($metadata['proposition_class'] ?? 'VIDEO_CONCERNS_SUBJECT'));
        $canonicalId = trim((string) ($metadata['canonical_video_id'] ?? $metadata['video_id'] ?? ''));
        $platform = strtolower(trim((string) ($metadata['platform'] ?? '')));
        $externalId = trim((string) ($metadata['external_video_id'] ?? ''));
        $reasons = [];
        if ($subjectId === '' || $propositionClass === '') $reasons[] = 'KNOWLEDGE_IDENTITY_REQUIRED_FIELD_MISSING';
        $canonical = null;
        if ($canonicalId !== '') {
            if ($videos === null) $reasons[] = 'VIDEO_CANONICAL_LOOKUP_REQUIRED';
            else $canonical = $videos->findVideoIdentity($canonicalId);
            if ($canonical === null && $videos !== null) $reasons[] = 'VIDEO_CANONICAL_LOOKUP_UNRESOLVED';
        }
        if ($canonical !== null) {
            $canonicalPlatform = strtolower(trim((string) ($canonical['platform'] ?? '')));
            $canonicalExternalId = trim((string) ($canonical['external_video_id'] ?? ''));
            if (($platform !== '' && $platform !== $canonicalPlatform) || ($externalId !== '' && $externalId !== $canonicalExternalId)) $reasons[] = 'VIDEO_IDENTITY_CONFLICT';
            $platform = $canonicalPlatform;
            $externalId = $canonicalExternalId;
        }
        if ($platform === '' || $externalId === '') $reasons[] = 'VIDEO_REFERENT_UNRESOLVED';
        $packet = ['subject_id' => $subjectId, 'proposition_class' => $propositionClass];
        if ($platform !== '' && $externalId !== '') $packet['video_referent'] = ['platform' => $platform, 'external_video_id' => $externalId];
        if ($reasons !== []) {
            $status = in_array('VIDEO_IDENTITY_CONFLICT', $reasons, true) ? KnowledgeClaimIdentityResolution::CONFLICTING : KnowledgeClaimIdentityResolution::UNRESOLVED;
            return new KnowledgeClaimIdentityResolution($status, $packet, $reasons);
        }
        return new KnowledgeClaimIdentityResolution(KnowledgeClaimIdentityResolution::RESOLVED, $packet);
    }

    /** @return array<string,mixed> */
    public static function contextForInput(string $type, array $provenance): array
    {
        $metadata = is_array($provenance['metadata'] ?? null) ? $provenance['metadata'] : [];
        $context = array_intersect_key($metadata, array_flip(['subject_id', 'facet', 'scope']));
        $context['claim_type'] = $type;
        $origin = strtoupper(trim((string) ($provenance['origin'] ?? $metadata['origin'] ?? '')));
        if ($origin === 'CAPTURE_VIDEO_SOURCE_PROVENANCE') $context['video_referent'] = self::videoReferent($metadata);
        ksort($context);
        return $context;
    }

    /** @return array<string,mixed> */
    public static function contextForClaim(KnowledgeClaim $claim): array
    {
        return self::contextForInput($claim->claimType, $claim->provenance);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function contextForAuditRow(array $row): array
    {
        $provenance = is_array($row['provenance'] ?? null) ? $row['provenance'] : [];
        $metadata = is_array($provenance['metadata'] ?? null) ? $provenance['metadata'] : [];
        foreach (['subject_id', 'canonical_subject_id', 'facet', 'scope', 'platform', 'external_video_id', 'canonical_video_id', 'video_id', 'origin'] as $key) {
            if (array_key_exists($key, $row) && !array_key_exists($key, $metadata)) $metadata[$key] = $row[$key];
        }
        $type = (string) ($row['claim_type'] ?? $metadata['claim_type'] ?? '');
        $provenance['metadata'] = $metadata;
        return self::contextForInput($type, $provenance);
    }

    public static function key(array $context): string
    {
        return (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,string>|array{unresolved:true} */
    private static function videoReferent(array $metadata): array
    {
        $canonical = trim((string) ($metadata['canonical_video_id'] ?? $metadata['video_id'] ?? ''));
        if ($canonical !== '') return ['canonical_video_id' => $canonical];
        $platform = strtolower(trim((string) ($metadata['platform'] ?? '')));
        $external = trim((string) ($metadata['external_video_id'] ?? ''));
        if ($platform !== '' && $external !== '') return ['external_video_id' => $external, 'platform' => $platform];
        return ['unresolved' => true];
    }
}
