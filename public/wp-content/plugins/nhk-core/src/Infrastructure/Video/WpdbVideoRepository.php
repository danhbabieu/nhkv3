<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Video;

use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Video\{Video, VideoException};
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbVideoRepository implements VideoRepository
{
    private string $table;

    public function __construct(private object $database)
    {
        $this->table = $database->prefix . 'nhk_videos';
    }

    public function findByCanonicalId(string $id): ?Video
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE canonical_uuid=%s LIMIT 1", UuidCodec::toBinary($id)), ARRAY_A));
    }

    public function findByExternalReference(string $platform, string $externalId): ?Video
    {
        return $this->hydrate($this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE platform=%s AND external_video_id=%s LIMIT 1", $platform, $externalId), ARRAY_A));
    }

    public function create(Video $video): Video
    {
        $existing = $this->findByCanonicalId($video->canonicalId);
        if ($existing !== null) {
            if ($this->sameVideo($existing, $video)) return $existing;
            throw new VideoException('Video external reference or identity already exists.');
        }

        $existing = $this->findByExternalReference($video->platform, $video->externalVideoId);
        if ($existing !== null) {
            if ($this->sameVideo($existing, $video)) return $existing;
            throw new VideoException('Video external reference or identity already exists.');
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $thumbnailSql = $video->thumbnailMediaId === null ? 'NULL' : '%s';
        $args = [UuidCodec::toBinary($video->canonicalId), $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, wp_json_encode($video->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $video->active ? 1 : 0, $video->revision, $now, $now];
        if ($video->thumbnailMediaId !== null) array_splice($args, 6, 0, [UuidCodec::toBinary($video->thumbnailMediaId)]);
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (canonical_uuid,platform,external_video_id,canonical_url,title,metadata_json,thumbnail_media_uuid,state,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,{$thumbnailSql},%d,%d,%s,%s)", ...$args));
        if ($ok === false) {
            $existing = $this->findByExternalReference($video->platform, $video->externalVideoId);
            if ($existing && $this->sameVideo($existing, $video)) return $existing;
            throw new VideoException('Video external reference or identity already exists.');
        }
        return $this->findByCanonicalId($video->canonicalId) ?? $video;
    }

    public function update(Video $video, int $expectedRevision): Video
    {
        $thumbnailSql = $video->thumbnailMediaId === null ? 'NULL' : '%s';
        $args = [$video->title, wp_json_encode($video->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $video->active ? 1 : 0, gmdate('Y-m-d H:i:s.u'), UuidCodec::toBinary($video->canonicalId), $expectedRevision];
        if ($video->thumbnailMediaId !== null) array_splice($args, 2, 0, [UuidCodec::toBinary($video->thumbnailMediaId)]);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET title=%s,metadata_json=%s,thumbnail_media_uuid={$thumbnailSql},state=%d,revision=revision+1,updated_at=%s WHERE canonical_uuid=%s AND revision=%d", ...$args));
        if ($ok !== 1) throw new VideoException('Video revision conflict.');
        return $this->findByCanonicalId($video->canonicalId) ?? $video;
    }

    public function list(bool $includeRetired = false): array
    {
        $state = $includeRetired ? '' : ' WHERE state=1';
        $rows = $this->database->get_results("SELECT * FROM {$this->table}{$state} ORDER BY id", ARRAY_A);
        return array_values(array_filter(array_map(fn (array $row): ?Video => $this->hydrate($row), $rows ?: []), static fn (?Video $video): bool => $video !== null));
    }

    private function hydrate(?array $row): ?Video
    {
        if (!$row) return null;
        try {
            $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($metadata)) return null;
        try {
            return new Video(UuidCodec::fromBinary($row['canonical_uuid']), (string) $row['platform'], (string) $row['external_video_id'], (string) $row['canonical_url'], (string) $row['title'], $metadata, $row['thumbnail_media_uuid'] === null ? null : UuidCodec::fromBinary($row['thumbnail_media_uuid']), (int) $row['state'] === 1, (int) $row['revision']);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sameVideo(Video $left, Video $right): bool
    {
        return $left->platform === $right->platform
            && $left->externalVideoId === $right->externalVideoId
            && $left->canonicalUrl === $right->canonicalUrl
            && $left->title === $right->title
            && $left->metadata === $right->metadata
            && $left->thumbnailMediaId === $right->thumbnailMediaId
            && $left->active === $right->active
            && $left->revision === $right->revision;
    }
}
