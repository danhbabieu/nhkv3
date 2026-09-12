<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class SnapshotArtifactCodec
{
    public static function encode(CanonicalSnapshot $snapshot): string
    {
        return SnapshotCanonicalizer::encode($snapshot->toArray()) . "\n";
    }

    public static function decode(string $contents): CanonicalSnapshot
    {
        try { $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('SNAPSHOT_ARTIFACT_INVALID_JSON'); }
        if (!is_array($payload) || !is_array($payload['manifest'] ?? null) || !is_array($payload['collections'] ?? null)) throw new \RuntimeException('SNAPSHOT_ARTIFACT_INVALID_SHAPE');
        return new CanonicalSnapshot($payload['manifest'], SnapshotCanonicalizer::collections($payload['collections']));
    }

    public static function write(string $path, CanonicalSnapshot $snapshot): string
    {
        if ($path === '' || str_contains($path, "\0")) throw new \InvalidArgumentException('SNAPSHOT_ARTIFACT_PATH_INVALID');
        if (is_file($path)) throw new \RuntimeException('SNAPSHOT_ARTIFACT_ALREADY_EXISTS');
        $contents = self::encode($snapshot);
        if (file_put_contents($path, $contents, LOCK_EX) === false) throw new \RuntimeException('SNAPSHOT_ARTIFACT_WRITE_FAILED');
        return hash('sha256', $contents);
    }
}
