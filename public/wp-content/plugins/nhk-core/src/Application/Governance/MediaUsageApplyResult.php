<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

/** Apply result for governed non-representative MediaUsage mutations. */
final readonly class MediaUsageApplyResult
{
    public function __construct(
        public string $canonicalId,
        public string $usageId,
        public array $mutation,
        public array $readback,
    ) {}
}
