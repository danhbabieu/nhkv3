<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\AuthorityEntity;

final class PublicExclusionReport
{
    public function __construct(private PublicEntityEligibilityPolicy $policy) {}

    /** @return array{eligible:bool,reasons:list<string>,warnings:list<string>} */
    public function evaluate(AuthorityEntity $entity): array
    {
        $result = $this->policy->evaluate($entity);
        return ['eligible' => $result->eligible, 'reasons' => $result->reasons, 'warnings' => $result->warnings];
    }
}
