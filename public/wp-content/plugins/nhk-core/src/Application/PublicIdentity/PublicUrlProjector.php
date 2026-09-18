<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\PublicIdentity\PublicUrlResult;

final class PublicUrlProjector
{
    public function __construct(private PublicRoutePolicyRegistry $policies, private PublicIdentityRepository $identities) {}
    public function project(AuthorityEntity $entity): PublicUrlResult
    {
        $policy = $this->policies->for($entity->entityType);
        if ($policy === null) return new PublicUrlResult(null, false, ['UNKNOWN_TYPE']);
        try {
            $identity = $this->identities->findByOwner('authority', $entity->canonicalId);
            return $identity === null ? new PublicUrlResult(null, false, ['MISSING_PUBLIC_IDENTITY']) : $policy->project($entity, $identity);
        } catch (\Throwable) {
            return new PublicUrlResult(null, false, ['PUBLIC_IDENTITY_UNAVAILABLE']);
        }
    }
}
