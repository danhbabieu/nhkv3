<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

/** Apply result for the MediaUsage representative operation. */
final readonly class MediaRepresentativeApplyResult
{
    public function __construct(
        public string $canonicalId,
        public string $usageId,
        public array $binding,
        public array $readback,
    ) {}
}
