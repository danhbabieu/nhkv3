<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Capture;

interface ClockTypeCanonicalMembershipDiagnosticsReader
{
    public function readClockTypeMemberships(string $sourceType, string $sourceId): ClockTypeCanonicalMembershipReadResult;
}
