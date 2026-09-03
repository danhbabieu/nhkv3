<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\MediaAssetRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Domain\Media\MediaAsset;
use NHK\Core\Shared\Uuid\UuidCodec;

final class PublicMediaAssetDelivery
{
    private const SAFE_MIME_TYPES = [
        'image/avif', 'image/gif', 'image/jpeg', 'image/png', 'image/webp',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'video/mp4',
    ];

    public function __construct(private MediaAssetRepository $assets, private MediaRepository $media, private string $storageRoot) {}

    public static function fromEnvironment(MediaAssetRepository $assets, MediaRepository $media): ?self
    {
        $root = defined('NHK_MEDIA_STORAGE_ROOT') ? (string) NHK_MEDIA_STORAGE_ROOT : (string) (getenv('NHK_MEDIA_STORAGE_ROOT') ?: '');
        if ($root === '' && function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir();
            $root = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        }
        return $root !== '' || function_exists('wp_upload_dir') ? new self($assets, $media, $root) : null;
    }

    /** @return array{asset:MediaAsset,path:string}|null */
    public function resolve(string $assetId): ?array
    {
        if (!UuidCodec::isValid($assetId)) return null;
        $asset = $this->assets->findByAssetId($assetId);
        if (!$asset || $asset->visibility !== 'PUBLIC' || !in_array(strtolower($asset->mimeType), self::SAFE_MIME_TYPES, true)) return null;
        $media = $this->media->findByCanonicalId($asset->mediaId);
        if (!$media || !$media->active || $media->readiness !== 'ready') return null;
        $root = realpath($this->storageRoot);
        if ($root === false || !is_dir($root)) return null;
        $storageKey = trim($asset->storageKey);
        if ($storageKey === '' || str_contains($storageKey, "\0")) return null;
        $candidate = $this->isAbsolute($storageKey) ? $storageKey : $root . DIRECTORY_SEPARATOR . ltrim($storageKey, '/\\');
        $path = realpath($candidate);
        if ($path === false || !is_file($path) || !$this->within($root, $path)) return null;
        $size = filesize($path);
        $checksum = hash_file('sha256', $path);
        if ($size === false || $size !== $asset->byteSize || !is_string($checksum) || !hash_equals(strtolower($asset->checksum), strtolower($checksum))) return null;
        return ['asset' => $asset, 'path' => $path];
    }

    /** @return array{asset:MediaAsset,path:string}|null */
    public function resolveByPublicFilename(string $filename): ?array
    {
        $wanted = basename(str_replace('\\', '/', trim($filename)));
        if ($wanted === '') return null;
        $resolver = new PublicMediaAssetUrlResolver();
        foreach ($this->media->list() as $media) {
            if (!$media->active || $media->readiness !== 'ready') continue;
            foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
                $candidate = is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename($asset->storageKey);
                if ($resolver->path($candidate) !== '/anh/' . rawurlencode($wanted)) continue;
                $resolved = $this->resolve($asset->assetId);
                if ($resolved !== null) return $resolved;
            }
        }
        return null;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function within(string $root, string $path): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root);
    }
}
