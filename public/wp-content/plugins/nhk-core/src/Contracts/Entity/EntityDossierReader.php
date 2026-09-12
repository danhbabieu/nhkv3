<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Entity;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only contract for the existing Entity Dossier owner. */
interface EntityDossierReader
{
    /** @return array<string,mixed> */
    public function forEntity(AuthorityEntity $entity): array;
}
