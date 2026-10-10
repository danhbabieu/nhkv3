<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\FacebookAudit;

use NHK\Core\Domain\FacebookAudit\{AccessReport, FacebookAuditScope, IdentityVerification, ReadPage};

interface FacebookAuditReadAdapter
{
    public function verifyIdentity(FacebookAuditScope $scope): IdentityVerification;
    public function inspectAccess(FacebookAuditScope $scope): AccessReport;
    public function pagePosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage;
    public function groupPosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage;
}
