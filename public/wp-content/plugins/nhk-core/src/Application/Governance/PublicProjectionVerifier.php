<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

/**
 * Verifies the public/read projection after canonical Apply. It never writes
 * a projection and never turns an unavailable route into an empty success.
 */
final class PublicProjectionVerifier
{
    /** @param callable(string,string):mixed $canonicalReader @param callable(string,mixed):?string $routeReader */
    public function __construct(private $canonicalReader, private $routeReader)
    {
    }

    /** @return array<string,mixed> */
    public function verify(array $applied, string $ownerType = '', string $canonicalId = ''): array
    {
        $readBack = $applied['canonical_readback'] ?? null;
        if (!is_array($readBack) || trim((string) ($readBack['canonical_id'] ?? '')) === '') throw new \RuntimeException('PROJECTION_CANONICAL_READBACK_REQUIRED');
        $canonicalId = trim($canonicalId) !== '' ? trim($canonicalId) : trim((string) $readBack['canonical_id']);
        $ownerType = strtolower(trim($ownerType));
        $owner = ($this->canonicalReader)($ownerType, $canonicalId);
        if ($owner === null) throw new \RuntimeException('PROJECTION_VERIFIER_UNAVAILABLE');

        $route = ($this->routeReader)($ownerType, $owner);
        if ($ownerType === 'video') {
            if (!is_object($owner) || !method_exists($owner, 'hasValidPublicReference') || !$owner->hasValidPublicReference() || !is_string($route) || trim($route) === '') {
                throw new \RuntimeException('PUBLIC_PROJECTION_NOT_AVAILABLE');
            }
            return ['projection_available' => true, 'frontend_available' => true, 'public_eligible' => true, 'route' => $route, 'canonical_readback' => $readBack, 'status' => 'VERIFIED'];
        }

        if (is_string($route) && trim($route) !== '') {
            return ['projection_available' => true, 'frontend_available' => true, 'public_eligible' => true, 'route' => $route, 'canonical_readback' => $readBack, 'status' => 'VERIFIED'];
        }

        // Knowledge/Source/Evidence/Media are canonical supporting owners,
        // not standalone public routes. Their projection proof is the
        // canonical owner read-back; CompletionCoordinator keeps public
        // routing NOT_APPLICABLE for those owner types.
        if (in_array($ownerType, ['knowledge', 'source', 'evidence', 'media'], true)) {
            return ['projection_available' => true, 'frontend_available' => true, 'public_eligible' => null, 'route' => null, 'canonical_readback' => $readBack, 'status' => 'CANONICAL_OWNER_VERIFIED'];
        }

        throw new \RuntimeException('PROJECTION_VERIFIER_UNAVAILABLE');
    }
}
