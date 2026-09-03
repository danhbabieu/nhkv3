<?php
declare(strict_types=1);

namespace NHK\Core\Application\Demo;

final readonly class CutoverRunResult
{
    public function __construct(public string $status, public ?string $reasonCode = null, public ?string $proposalId = null, public ?string $proposalFingerprint = null) {}
}
