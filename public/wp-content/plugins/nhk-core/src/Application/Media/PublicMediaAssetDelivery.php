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

    private ?\Closure $attachmentPathResolver;

    public function __construct(private MediaAssetRepository $assets, private MediaRepository $media, private string $storageRoot, ?callable $attachmentPathResolver = null)
    {
        $this->attachmentPathResolver = $attachmentPathResolver === null ? null : \Closure::fromCallable($attachmentPathResolver);
    }

    public static function fromEnvironment(MediaAssetRepository $assets, MediaRepository $media): ?self
    {
        $root = defined('NHK_MEDIA_STORAGE_ROOT') ? (string) NHK_MEDIA_STORAGE_ROOT : (string) (getenv('NHK_MEDIA_STORAGE_ROOT') ?: '');
        if ($root === '' && function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir();
            $root = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        }
        $attachmentPathResolver = static function (MediaAsset $asset): ?string {
            $attachmentId = (int) ($asset->metadata['wordpress_attachment_id'] ?? 0);
            if ($attachmentId < 1 || !function_exists('get_attached_file')) return null;
            $path = get_attached_file($attachmentId, true);
            return is_string($path) && $path !== '' ? $path : null;
        };
        return $root !== '' || function_exists('wp_upload_dir') ? new self($assets, $media, $root, $attachmentPathResolver) : null;
    }

    /** @return array{asset:MediaAsset,path:string}|null */
    public function resolve(string $assetId, bool $requireWebp = false): ?array
    {
        if (!UuidCodec::isValid($assetId)) return null;
        $asset = $this->assets->findByAssetId($assetId);
        if (!$asset || $asset->visibility !== 'PUBLIC' || !in_array(strtolower($asset->mimeType), self::SAFE_MIME_TYPES, true)) return null;
        if ($requireWebp && strtolower($asset->mimeType) !== 'image/webp') return null;
        $media = $this->media->findByCanonicalId($asset->mediaId);
        if (!$media || !$media->active || $media->readiness !== 'ready') return null;
        $root = realpath($this->storageRoot);
        if ($root === false || !is_dir($root)) return null;
        $storageKey = trim($asset->storageKey);
        if ($storageKey === '' || str_contains($storageKey, "\0")) return null;
        $candidate = $this->physicalCandidate($asset, $root, $storageKey);
        if ($candidate === null) return null;
        $path = realpath($candidate);
        if ($path === false || !is_file($path) || !$this->within($root, $path)) return null;
        $size = filesize($path);
        $checksum = hash_file('sha256', $path);
        if ($size === false || $size !== $asset->byteSize || !is_string($checksum) || !hash_equals(strtolower($asset->checksum), strtolower($checksum))) return null;
        if ($requireWebp && !$this->isWebp($path, $asset)) return null;
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
            $asset = (new PublicMediaAssetSelector())->canonical($this->assets->listByMediaId($media->canonicalId));
            if (!$asset instanceof MediaAsset) continue;
            $candidate = is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename($asset->storageKey);
            if ($resolver->path($candidate) !== '/anh/' . rawurlencode($wanted)) continue;
            if (strtolower($asset->mimeType) !== 'image/webp') continue;
            $resolved = $this->resolve($asset->assetId, true);
            if ($resolved !== null) return $resolved;
        }
        return null;
    }

    public function canonicalAsset(MediaAsset $asset): ?MediaAsset
    {
        $selected = (new PublicMediaAssetSelector())->canonical($this->assets->listByMediaId($asset->mediaId));
        return $selected !== null && $this->resolve($selected->assetId) !== null ? $selected : null;
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

    private function physicalCandidate(MediaAsset $asset, string $root, string $storageKey): ?string
    {
        $attachmentId = (int) ($asset->metadata['wordpress_attachment_id'] ?? 0);
        if ($attachmentId > 0) {
            if ($this->attachmentPathResolver === null) return null;
            $path = ($this->attachmentPathResolver)($asset);
            return is_string($path) && $path !== '' ? $path : null;
        }
        return $this->isAbsolute($storageKey) ? $storageKey : $root . DIRECTORY_SEPARATOR . ltrim($storageKey, '/\\');
    }

    private function isWebp(string $path, MediaAsset $asset): bool
    {
        $magic = @file_get_contents($path, false, null, 0, 12);
        if (!is_string($magic) || strlen($magic) < 12 || substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'WEBP') return false;
        $info = @getimagesize($path);
        if (!is_array($info) || strtolower((string) ($info['mime'] ?? '')) !== 'image/webp') return false;
        return ($asset->width === null || (int) ($info[0] ?? 0) === $asset->width)
            && ($asset->height === null || (int) ($info[1] ?? 0) === $asset->height);
    }
}
