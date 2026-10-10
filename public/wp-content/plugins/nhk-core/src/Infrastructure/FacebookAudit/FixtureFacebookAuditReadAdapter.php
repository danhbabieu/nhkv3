<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\FacebookAudit;

use InvalidArgumentException;
use NHK\Core\Contracts\FacebookAudit\FacebookAuditReadAdapter;
use NHK\Core\Domain\FacebookAudit\{AccessReport, FacebookAuditScope, IdentityVerification, ReadPage};

final class FixtureFacebookAuditReadAdapter implements FacebookAuditReadAdapter
{
    /** @var array<string,mixed> */
    private array $fixture;
    /** @param array<string,int> $transientFailures */
    public function __construct(string $fixturePath, private array $transientFailures = [], private bool $repeatCursor = false)
    {
        $decoded = json_decode((string) file_get_contents($fixturePath), true);
        if (!is_array($decoded)) throw new InvalidArgumentException('INVALID_FIXTURE');
        $this->fixture = $decoded;
    }

    public function verifyIdentity(FacebookAuditScope $scope): IdentityVerification
    {
        $identity = is_array($this->fixture['identity'] ?? null) ? $this->fixture['identity'] : [];
        $url = trim((string) ($identity['canonical_url'] ?? ''));
        $id = trim((string) ($identity['page_id'] ?? ''));
        if ($url !== $scope->targetUrl || $id === '') return IdentityVerification::unverified('IDENTITY_NOT_VERIFIED');
        if ($scope->verifiedPageId !== null && $scope->verifiedPageId !== $id) return IdentityVerification::unverified('PAGE_ID_MISMATCH');
        return IdentityVerification::verified($id, (string) ($identity['page_name'] ?? ''), $url);
    }

    public function inspectAccess(FacebookAuditScope $scope): AccessReport
    {
        $scope->assertCollectionReady();
        return new AccessReport(is_array($this->fixture['access'] ?? null) ? $this->fixture['access'] : []);
    }

    public function pagePosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage
    {
        return $this->readPosts($scope, 'page_posts', $after, $limit, 'PAGE');
    }

    public function groupPosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage
    {
        return $this->readPosts($scope, 'group_posts', $after, $limit, 'GROUP');
    }

    private function readPosts(FacebookAuditScope $scope, string $key, ?string $after, int $limit, string $source): ReadPage
    {
        $scope->assertCollectionReady();
        $offset = $after === null ? 0 : (int) $after;
        $failureKey = $key . ':' . ($after ?? '0');
        if (($this->transientFailures[$failureKey] ?? 0) > 0) {
            $this->transientFailures[$failureKey]--;
            return new ReadPage([], $after, 'UNAVAILABLE', true, 'TRANSIENT_FIXTURE_FAILURE');
        }
        $all = array_values(array_filter((array) ($this->fixture[$key] ?? []), 'is_array'));
        $items = array_slice($all, $offset, max(1, $limit));
        $next = $offset + count($items) < count($all) ? (string) ($offset + count($items)) : null;
        if ($this->repeatCursor && $after !== null) $next = $after;
        return new ReadPage($items, $next, 'OK', false, null);
    }
}
