<?php
declare(strict_types=1);

namespace NHK\Core\Application\Authority;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;

final class AuthorityParityAudit
{
    /** @var callable(string): int */
    public function __construct(private $physicalCounter)
    {
    }

    /** @return list<array{type:string,physical_rows:int,hydrated_rows:int,query_rows:int,status:string,reason_code:?string}> */
    public function run(EntityTypeRegistry $types, AuthorityRepository $authority): array
    {
        $result = [];
        foreach ($types->all() as $definition) {
            $physical = (int) ($this->physicalCounter)($definition->type);
            $hydrated = count($authority->listByType($definition->type, true));
            $query = count($authority->listByType($definition->type));
            $status = $physical === 0 ? 'EMPTY_VALID' : ($hydrated === $physical ? 'OK' : 'HYDRATION_LOSS');
            $result[] = [
                'type' => $definition->type,
                'physical_rows' => $physical,
                'hydrated_rows' => $hydrated,
                'query_rows' => $query,
                'status' => $status,
                'reason_code' => $status === 'HYDRATION_LOSS' ? 'HYDRATION_LOSS' : null,
            ];
        }
        return $result;
    }
}
