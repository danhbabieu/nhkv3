<?php
declare(strict_types=1);

namespace NHK\Core\Application\FacebookAudit;

use NHK\Core\Application\FacebookAudit\FacebookAuditCheckpoint;

final readonly class FacebookAuditCollectionResult
{
    /** @param list<array<string,mixed>> $pagePosts @param list<array<string,mixed>> $groupPosts @param list<string> $blockers */
    public function __construct(public array $pagePosts, public array $groupPosts, public string $status, public FacebookAuditCheckpoint $checkpoint, public array $blockers = []) {}
}
