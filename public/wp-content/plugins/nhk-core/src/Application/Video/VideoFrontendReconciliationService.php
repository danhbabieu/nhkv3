<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Reconciles an existing canonical Video against the public frontend sources.
 *
 * The current frontend projection is a derived read model, so this operation
 * intentionally has no writer. Its idempotency is the deterministic
 * owner-bound readback; a missing projection is reported, never manufactured
 * with a WordPress post or a direct database write.
 */
final class VideoFrontendReconciliationService
{
    /** @param callable(string):?array<string,mixed> $detailReadback @param callable(string):?array<string,mixed> $routeReadback @param callable(int,int):array<string,mixed> $archiveReadback @param callable():array<string,mixed> $homepageReadback */
    public function __construct(
        private VideoRepository $videos,
        private PublicIdentityRepository $publicIdentities,
        private $detailReadback,
        private $routeReadback,
        private $archiveReadback,
        private $homepageReadback,
    ) {}

    /** @return array<string,mixed> */
    public function reconcile(string $videoOwnerId, string $idempotencyKey, bool $confirmed = true): array
    {
        $videoOwnerId = trim($videoOwnerId);
        $idempotencyKey = trim($idempotencyKey);
        if (!UuidCodec::isValid($videoOwnerId)) throw new \InvalidArgumentException('video_owner_id must be a valid UUID.');
        if ($idempotencyKey === '') throw new \InvalidArgumentException('idempotency_key is required.');
        if (!$confirmed) throw new \InvalidArgumentException('FRONTEND_RECONCILIATION_CONFIRMATION_REQUIRED');

        $result = [
            'status' => 'REVIEW_REQUIRED',
            'video_owner_id' => $videoOwnerId,
            'canonical_reused' => false,
            'idempotency_key' => $idempotencyKey,
            'public_identity' => ['identity_id' => null, 'path' => null],
            'projection' => ['valid' => false, 'blockers' => []],
            'detail' => ['state' => 'NOT_APPLICABLE', 'verified' => false, 'canonical_verified' => false, 'route_verified' => false],
            'archive' => ['state' => 'NOT_APPLICABLE', 'verified' => false],
            'homepage' => ['state' => 'NOT_APPLICABLE', 'verified' => false],
            'frontend_state' => 'REVIEW_REQUIRED',
            'blockers' => [],
            'created_owner_count' => 0,
            'duplicate_count' => 0,
            'capture_retry' => false,
            'writes' => [],
        ];

        $video = $this->videos->findByCanonicalId($videoOwnerId);
        if (!$video instanceof Video) return $this->blocked($result, 'VIDEO_CANONICAL_READBACK_UNAVAILABLE');
        $result['canonical_reused'] = true;
        if (!$video->active) return $this->blocked($result, 'VIDEO_CANONICAL_OWNER_INACTIVE');

        $identity = $this->publicIdentities->findCurrentByOwner('video', $videoOwnerId, 'video');
        if (!is_array($identity)) return $this->blocked($result, 'PUBLIC_IDENTITY_NOT_PERSISTED');
        $result['public_identity'] = [
            'identity_id' => (string) ($identity['identity_id'] ?? ''),
            'path' => (string) ($identity['current_path'] ?? ''),
        ];

        $projection = (new VideoFrontendProjection($this->publicIdentities))->project($video);
        $result['projection'] = [
            'valid' => ($projection['frontend_available'] ?? false) === true,
            'blockers' => array_values(array_map('strval', (array) ($projection['blockers'] ?? []))),
        ];
        if (($projection['frontend_available'] ?? false) !== true) {
            return $this->blocked($result, ...$result['projection']['blockers']);
        }

        $path = (string) (($projection['item']['public_url'] ?? ''));
        if ((string) ($identity['current_path'] ?? '') !== $path) {
            return $this->blocked($result, 'PUBLIC_IDENTITY_FRONTEND_PATH_MISMATCH');
        }
        $detail = is_callable($this->detailReadback) ? ($this->detailReadback)($videoOwnerId) : null;
        $route = is_callable($this->routeReadback) ? ($this->routeReadback)($this->slugFromPath($path)) : null;
        $canonicalDetailVerified = $this->matches($detail, $videoOwnerId, $path);
        $routeVerified = $this->matches($route, $videoOwnerId, $path);
        $result['detail'] = [
            'state' => $canonicalDetailVerified && $routeVerified ? 'VERIFIED' : 'BLOCKED',
            'verified' => $canonicalDetailVerified && $routeVerified,
            'canonical_verified' => $canonicalDetailVerified,
            'route_verified' => $routeVerified,
        ];
        if (!$canonicalDetailVerified) $result['blockers'][] = 'VIDEO_DETAIL_READBACK_FAILED';
        if (!$routeVerified) $result['blockers'][] = 'VIDEO_DETAIL_ROUTE_READBACK_FAILED';

        $archive = is_callable($this->archiveReadback) ? (array) ($this->archiveReadback)(1, 100) : [];
        $archiveItems = array_values(array_filter((array) ($archive['items'] ?? []), fn (mixed $item): bool => $this->matches($item, $videoOwnerId, $path)));
        $result['archive'] = [
            'state' => count($archiveItems) === 1 ? 'VERIFIED' : 'BLOCKED',
            'verified' => count($archiveItems) === 1,
            'match_count' => count($archiveItems),
        ];
        if (count($archiveItems) !== 1) $result['blockers'][] = 'VIDEO_ARCHIVE_READBACK_FAILED';

        $home = is_callable($this->homepageReadback) ? (array) ($this->homepageReadback)() : [];
        $homeItems = array_values(array_filter((array) ($home['videos'] ?? []), fn (mixed $item): bool => $this->matches($item, $videoOwnerId, $path)));
        $homeTotal = (int) ($home['videos_total'] ?? 0);
        $homeState = count($homeItems) === 1 ? 'VERIFIED' : ($homeTotal > 0 ? 'NOT_APPLICABLE' : 'NOT_APPLICABLE');
        $result['homepage'] = [
            'state' => $homeState,
            'verified' => $homeState === 'VERIFIED',
            'match_count' => count($homeItems),
            'eligible_total' => $homeTotal,
        ];

        $result['blockers'] = array_values(array_unique(array_map('strval', $result['blockers'])));
        if ($result['blockers'] === []) {
            $result['status'] = 'VERIFIED';
            $result['frontend_state'] = 'VERIFIED';
        }
        return $result;
    }

    private function matches(mixed $item, string $ownerId, string $path): bool
    {
        return is_array($item)
            && (string) ($item['canonical_id'] ?? '') === $ownerId
            && (string) ($item['public_url'] ?? ($item['url'] ?? '')) === $path;
    }

    private function slugFromPath(string $path): string
    {
        return preg_match('#^/video/([^/]+)/$#', $path, $matches) === 1 ? rawurldecode((string) $matches[1]) : '';
    }

    /** @param list<string> $blockers @return array<string,mixed> */
    private function blocked(array $result, string ...$blockers): array
    {
        $result['blockers'] = array_values(array_unique(array_merge($result['blockers'], array_filter($blockers, static fn (string $code): bool => $code !== ''))));
        $result['status'] = 'REVIEW_REQUIRED';
        $result['frontend_state'] = 'REVIEW_REQUIRED';
        return $result;
    }
}
