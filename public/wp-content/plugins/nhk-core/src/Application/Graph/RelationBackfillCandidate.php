<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;

final readonly class RelationBackfillCandidate
{
    public function __construct(public string $recordUuid, public string $stableKey, public string $recordType, public string $sourceType, public string $sourceUuid, public string $predicate, public string $targetType, public string $targetUuid, public string $reason = 'DETERMINISTIC', public float $confidence = 1.0) {}

    /** @return array<string,mixed> */
    public function toArray(): array { return get_object_vars($this); }
}
