<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;

final class ClaimProjectionVisibility
{
    public static function status(KnowledgeClaim $claim): string
    {
        $metadata = $claim->provenance['metadata'] ?? [];
        $values = is_array($metadata) ? $metadata : [];
        foreach (['projection_status', 'claim_status', 'knowledge_status', 'verification_status', 'status'] as $key) {
            $value = strtoupper(trim((string) ($values[$key] ?? '')));
            if (in_array($value, ['PRIVATE', 'HIDDEN'], true)) return 'PRIVATE';
            if (in_array($value, ['SUPERSEDED', 'REPLACED'], true)) return 'SUPERSEDED';
            if (in_array($value, ['DEPRECATED', 'RETIRED'], true)) return 'DEPRECATED';
            if (in_array($value, ['DISPUTED', 'CONTESTED'], true)) return 'DISPUTED';
            if (in_array($value, ['UNCERTAIN', 'UNVERIFIED', 'NEEDS_CONFIRMATION'], true)) return 'UNCERTAIN';
            if (in_array($value, ['APPROVED', 'PUBLIC', 'VERIFIED'], true)) return 'APPROVED';
        }
        return $claim->active && $claim->isPublic() ? 'APPROVED' : 'PRIVATE';
    }

    public static function eligible(KnowledgeClaim $claim): bool
    {
        return $claim->active && $claim->isPublic() && in_array(self::status($claim), ['APPROVED', 'DISPUTED', 'UNCERTAIN'], true);
    }
}
