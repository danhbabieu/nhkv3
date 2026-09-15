<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

/**
 * Stores source originals outside the WordPress document root. The returned
 * key is a semantic storage key only; it is never a public URL.
 */
final class PrivateMediaSourceStorage
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/\\');
        if ($this->root === '' || (!is_dir($this->root) && !mkdir($this->root, 0750, true) && !is_dir($this->root))) {
            throw new \RuntimeException('NHK_PRIVATE_MEDIA_ROOT_UNAVAILABLE');
        }
        if (defined('ABSPATH')) {
            $documentRoot = rtrim((string) ABSPATH, '/\\') . '/';
            $candidate = str_replace('\\', '/', $this->root) . '/';
            if (str_starts_with($candidate, str_replace('\\', '/', $documentRoot))) throw new \RuntimeException('NHK_PRIVATE_MEDIA_ROOT_PUBLIC');
        }
    }

    public static function fromWordPress(): self
    {
        $configured = getenv('NHK_PRIVATE_MEDIA_STORAGE_ROOT');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : (defined('ABSPATH') ? dirname(rtrim((string) ABSPATH, '/\\')) . '/.nhk-private-media' : sys_get_temp_dir() . '/nhk-private-media');
        return new self($root);
    }

    public function store(string $contents, string $extension): string
    {
        if ($contents === '') throw new \InvalidArgumentException('NHK_PRIVATE_MEDIA_EMPTY');
        $extension = strtolower(ltrim(trim($extension), '.'));
        if (!in_array($extension, self::EXTENSIONS, true)) throw new \InvalidArgumentException('NHK_PRIVATE_MEDIA_EXTENSION_REJECTED');
        $key = 'private/' . hash('sha256', $contents) . '.' . $extension;
        if (!is_dir($this->root . '/private') && !mkdir($this->root . '/private', 0750, true) && !is_dir($this->root . '/private')) throw new \RuntimeException('NHK_PRIVATE_MEDIA_WRITE_FAILED');
        $path = $this->absolutePath($key);
        if (!is_file($path) && file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new \RuntimeException('NHK_PRIVATE_MEDIA_WRITE_FAILED');
        }
        if (!is_file($path) || hash_file('sha256', $path) !== hash('sha256', $contents)) throw new \RuntimeException('NHK_PRIVATE_MEDIA_READBACK_FAILED');
        @chmod($path, 0640);
        return $key;
    }

    public function path(string $key): ?string
    {
        if (!preg_match('#^private/[a-f0-9]{64}\\.(?:jpg|jpeg|png|gif|webp)$#', $key)) {
            throw new \InvalidArgumentException('NHK_PRIVATE_MEDIA_KEY_REJECTED');
        }
        $path = $this->absolutePath($key);
        $realRoot = realpath($this->root);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false || !is_file($realPath) || !$this->within($realRoot, $realPath)) return null;
        return $realPath;
    }

    public function publicUrl(string $key): ?string
    {
        return null;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if ($path !== null && is_file($path)) @unlink($path);
    }

    private function absolutePath(string $key): string
    {
        return $this->root . '/' . $key;
    }

    private function within(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with(str_replace('\\', '/', $path), $root);
    }
}
