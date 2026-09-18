<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal};
use NHK\Core\Domain\Video\{VideoException, YouTubeSourceSnapshot, YouTubeVideoIdentity};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Planning boundary for the bounded YouTube source refresh command.
 * It fetches and snapshots source data, but never writes a Video directly.
 */
final class VideoSourceRefreshCommand
{
    /** @param callable(YouTubeVideoIdentity):array<string,mixed> $fetch */
    public function __construct(private VideoRepository $videos, private GovernanceService $governance, private $fetch, private ?StagingAcceptanceScopeVerifier $stagingScopeVerifier = null)
    {
    }

    /** @return array<string,mixed> */
    public function prepare(string $videoId, int $expectedRevision, ?int $expectedSourceRevision, string $idempotencyKey, ?array $stagingAcceptance = null): array
    {
        if (!UuidCodec::isValid($videoId)) throw new VideoException('VIDEO_ID_INVALID');
        if ($expectedRevision < 1) throw new VideoException('VIDEO_REVISION_REQUIRED');
        if (trim($idempotencyKey) === '') throw new VideoException('IDEMPOTENCY_KEY_REQUIRED');
        if ($this->stagingScopeVerifier !== null) {
            if (!is_array($stagingAcceptance)) throw new VideoException('STAGING_SCOPE_REQUIRED');
            if (!$this->stagingScopeVerifier->verifyVideoSourceRefresh($stagingAcceptance, $videoId, $expectedRevision, $expectedSourceRevision, $idempotencyKey)) throw new VideoException('STAGING_SCOPE_NOT_APPROVED');
        }

        $existing = $this->governance->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $binding = is_array($existing->payload['request_binding'] ?? null) ? $existing->payload['request_binding'] : [];
            $sameBinding = ($binding['video_id'] ?? null) === $videoId
                && (int) ($binding['expected_revision'] ?? 0) === $expectedRevision
                && ($expectedSourceRevision === null || (int) ($binding['expected_source_revision'] ?? -1) === $expectedSourceRevision);
            if (!$sameBinding || $existing->operation !== 'source_refresh' || $existing->entityType !== 'video') {
                throw new VideoException('IDEMPOTENCY_KEY_CONFLICT');
            }
            return $this->proposalResult($existing, true);
        }

        $video = $this->videos->findByCanonicalId($videoId);
        if ($video === null) throw new VideoException('VIDEO_NOT_FOUND');
        if ($video->revision !== $expectedRevision) throw new VideoException('VIDEO_REVISION_CONFLICT');
        if ($video->platform !== 'youtube') throw new VideoException('VIDEO_SOURCE_PLATFORM_UNSUPPORTED');

        $sourceKey = is_array($video->metadata['source_snapshot'] ?? null) ? 'source_snapshot' : 'source';
        $currentSource = is_array($video->metadata[$sourceKey] ?? null) ? $video->metadata[$sourceKey] : [];
        $currentSourceRevision = max(0, (int) ($currentSource['source_revision'] ?? $video->metadata['source_revision'] ?? 0));
        $expectedSourceRevision ??= $currentSourceRevision;
        if ($expectedSourceRevision !== $currentSourceRevision) throw new VideoException('SOURCE_REVISION_CONFLICT');

        try {
            $data = ($this->fetch)(new YouTubeVideoIdentity('youtube', $video->externalVideoId, $video->canonicalUrl));
            if (!is_array($data)) throw new VideoException('SOURCE_RESPONSE_INVALID');
            $data['platform'] = 'youtube';
            $data['external_video_id'] = $video->externalVideoId;
            $data['canonical_source_url'] = $video->canonicalUrl;
            $snapshot = YouTubeSourceSnapshot::fromArray($data);
        } catch (VideoException $error) {
            return ['status' => 'SOURCE_UNAVAILABLE', 'mutated' => false, 'reason' => $error->getMessage(), 'video_id' => $videoId];
        } catch (\Throwable) {
            return ['status' => 'SOURCE_UNAVAILABLE', 'mutated' => false, 'reason' => 'SOURCE_FETCH_FAILED', 'video_id' => $videoId];
        }

        $comparison = (new VideoSyncService())->compare($video, $snapshot);
        $source = $snapshot->toArray();
        $source['source_revision'] = $currentSourceRevision + ($comparison->status === 'NO_CHANGE' ? 0 : 1);
        $source['source_snapshot_hash'] = $snapshot->hash();
        $binding = ['video_id' => $videoId, 'expected_revision' => $expectedRevision, 'expected_source_revision' => $expectedSourceRevision];
        $payload = [
            'request_binding' => $binding,
            'request_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize(['video_id' => $videoId, 'expected_revision' => $expectedRevision, 'expected_source_revision' => $expectedSourceRevision, 'idempotency_key' => $idempotencyKey])),
            'source_key' => $sourceKey,
            'expected_source_revision' => $expectedSourceRevision,
            'source_snapshot' => $source,
            'comparison_status' => $comparison->status,
            'changed_fields' => $comparison->changedFields,
            'no_op' => $comparison->status === 'NO_CHANGE',
        ];
        if ($stagingAcceptance !== null) {
            $payload['staging_acceptance'] = $stagingAcceptance;
            $payload['capture_id'] = (string) ($stagingAcceptance['capture_id'] ?? '');
            $payload['capture_fingerprint'] = (string) ($stagingAcceptance['capture_fingerprint'] ?? '');
        }
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize($payload));
        $proposal = new Proposal(
            UuidCodec::newV7(), $videoId, 'source_refresh', $payload, $fingerprint,
            $expectedRevision, hash('sha256', 'video-source-refresh'),
            actor: function_exists('get_current_user_id') ? (string) get_current_user_id() : '0',
            idempotencyKey: $idempotencyKey, targetUuid: $videoId, entityType: 'video',
        );
        return $this->proposalResult($this->governance->create($proposal), false);
    }

    /** @return array<string,mixed> */
    private function proposalResult(Proposal $proposal, bool $idempotent): array
    {
        return [
            'status' => $proposal->payload['no_op'] ?? false ? 'NO_CHANGE' : 'PROPOSAL_CREATED',
            'mutated' => false,
            'idempotent' => $idempotent,
            'proposal_id' => $proposal->id,
            'proposal_state' => $proposal->state->value,
            'video_id' => $proposal->subjectId,
            'expected_revision' => $proposal->expectedRevision,
            'payload' => $proposal->payload,
        ];
    }
}
