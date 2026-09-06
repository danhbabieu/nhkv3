<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\PublicIdentity;

interface PublicSlugMigrationSource
{
    /** @return list<array<string,mixed>> */
    public function candidates(): array;
}
