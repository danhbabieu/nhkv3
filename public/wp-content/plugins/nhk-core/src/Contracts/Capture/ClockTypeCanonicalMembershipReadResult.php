<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Capture;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** @internal Read-only result for the canonical Graph membership adapter. */
final readonly class ClockTypeCanonicalMembershipReadResult
{
    /** @param list<AuthorityEntity> $members @param list<string> $diagnostics */
    public function __construct(
        public string $status,
        public array $members = [],
        public array $diagnostics = [],
    ) {}
}
