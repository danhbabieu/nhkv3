<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

final class PlanReapprovalRequired extends \InvalidArgumentException
{
    /** @param array<string,mixed> $packet */
    public function __construct(public readonly array $packet)
    {
        parent::__construct('PLAN_REAPPROVAL_REQUIRED');
    }
}
