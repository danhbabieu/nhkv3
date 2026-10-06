<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Knowledge-owner identity context; Source is support, not Claim identity. */
final class KnowledgeClaimIdentity
{
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
