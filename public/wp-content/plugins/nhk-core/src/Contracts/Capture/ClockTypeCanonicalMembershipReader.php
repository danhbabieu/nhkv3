<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Capture;

use NHK\Core\Domain\Authority\AuthorityEntity;

/**
 * Read-only adapter for already-canonical classification membership.
 *
 * The adapter deliberately returns Authority entities instead of accepting a
 * write command. The production adapter reads through GraphService and keeps
 * Graph as the source of relation truth.
 */
interface ClockTypeCanonicalMembershipReader
{
    /** @return list<AuthorityEntity> */
    public function listClockTypesForSubject(string $sourceType, string $sourceId): array;
}
