<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\FacebookAudit;

use InvalidArgumentException;
use NHK\Core\Contracts\FacebookAudit\FacebookAuditReadAdapter;
use NHK\Core\Domain\FacebookAudit\{AccessReport, FacebookAuditScope, IdentityVerification, ReadPage};

final class MetaFacebookAuditReadAdapter implements FacebookAuditReadAdapter
{
    public function __construct(private MetaGraphHttpClient $client, private string $accessToken)
    {
        if (trim($this->accessToken) === '') throw new InvalidArgumentException('META_CREDENTIALS_MISSING');
    }

    public function verifyIdentity(FacebookAuditScope $scope): IdentityVerification
    {
        $response = $this->client->get('/donghonhakho.vn', ['fields' => 'id,name,link'], $this->accessToken);
        $data = $response['data'] ?? null;
        if (!$response['ok'] || !is_array($data)) return IdentityVerification::unverified('IDENTITY_NOT_VERIFIED');
        $id = trim((string) ($data['id'] ?? ''));
        $url = rtrim(trim((string) ($data['link'] ?? '')), '/');
        if ($id === '' || $url !== $scope->targetUrl) return IdentityVerification::unverified('IDENTITY_NOT_VERIFIED');
        if ($scope->verifiedPageId !== null && $scope->verifiedPageId !== $id) return IdentityVerification::unverified('PAGE_ID_MISMATCH');
        return IdentityVerification::verified($id, (string) ($data['name'] ?? ''), $url);
    }

    public function inspectAccess(FacebookAuditScope $scope): AccessReport
    {
        $scope->assertCollectionReady();
        $id = $scope->verifiedPageId;
        $statuses = [
            'page_metadata' => $this->probe('/' . $id, ['fields' => 'id,name,link']),
            'page_posts' => $this->probe('/' . $id . '/posts', ['fields' => 'id', 'limit' => '1']),
            'engagement_metrics' => $this->probe('/' . $id . '/posts', ['fields' => 'id,reactions.limit(0).summary(true),comments.limit(0).summary(true),shares', 'limit' => '1']),
            'photos_videos' => $this->probe('/' . $id . '/photos', ['fields' => 'id', 'limit' => '1']),
            'reels' => $this->probe('/' . $id . '/video_reels', ['fields' => 'id', 'limit' => '1']),
            'insights' => $this->probe('/' . $id . '/insights', ['metric' => 'page_impressions', 'period' => 'day']),
            'known_groups' => 'UNSUPPORTED',
            'delete_capability' => 'UNSUPPORTED',
        ];
        return new AccessReport($statuses);
    }

    public function pagePosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage
    {
        $scope->assertCollectionReady();
        $query = ['fields' => 'id,permalink_url,created_time,message,type,attachments,reactions.limit(0).summary(true),comments.limit(0).summary(true),shares,views', 'limit' => (string) max(1, min(100, $limit))];
        if ($after !== null && trim($after) !== '') $query['after'] = trim($after);
        $response = $this->client->get('/' . $scope->verifiedPageId . '/posts', $query, $this->accessToken);
        return $this->pageFromResponse($response);
    }

    public function groupPosts(FacebookAuditScope $scope, ?string $after, int $limit): ReadPage
    {
        $scope->assertCollectionReady();
        return new ReadPage([], null, 'INACCESSIBLE', false, 'GROUP_DISCOVERY_UNSUPPORTED');
    }

    private function probe(string $path, array $query): string
    {
        $response = $this->client->get($path, $query, $this->accessToken);
        if (($response['ok'] ?? false) === true) return 'GRANTED';
        return in_array((int) ($response['status'] ?? 0), [401, 403], true) ? 'DENIED' : 'INACCESSIBLE';
    }

    /** @param array<string,mixed> $response */
    private function pageFromResponse(array $response): ReadPage
    {
        if (($response['ok'] ?? false) !== true) return new ReadPage([], null, 'INACCESSIBLE', false, 'META_POSTS_INACCESSIBLE');
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rows = array_values(array_filter((array) ($data['data'] ?? []), 'is_array'));
        $paging = is_array($data['paging'] ?? null) ? $data['paging'] : [];
        $cursors = is_array($paging['cursors'] ?? null) ? $paging['cursors'] : [];
        $next = trim((string) ($cursors['after'] ?? ''));
        return new ReadPage($rows, $next === '' ? null : $next);
    }
}
