<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

/**
 * Resolves a first-party WordPress attachment URL to its owning attachment.
 *
 * The URL is only a locator. Resolution is proved by WordPress storage
 * metadata, never by a fuzzy filename search or by downloading the URL.
 */
final class WordPressAttachmentUrlResolver
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private object $database, private array $allowedOrigins, private ?string $uploadBasePath = null)
    {
    }

    public function resolve(string $url): int
    {
        $relative = $this->relativeUploadPath($url);
        $attachmentIds = $this->findByAttachedFile($relative);
        if ($attachmentIds === []) $attachmentIds = $this->findByDerivative($relative);
        if (count($attachmentIds) !== 1) {
            throw new \InvalidArgumentException($attachmentIds === [] ? 'EXISTING_MEDIA_ATTACHMENT_NOT_FOUND' : 'EXISTING_MEDIA_ATTACHMENT_AMBIGUOUS');
        }

        $attachmentId = (int) $attachmentIds[0];
        $this->assertReadableImage($attachmentId);
        return $attachmentId;
    }

    /**
     * Normalize and validate the URL without resolving it. Kept public so the
     * fail-closed URL policy can be tested without bootstrapping WordPress.
     */
    public function relativeUploadPath(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || trim((string) ($parts['host'] ?? '')) === '' || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('EXISTING_MEDIA_URL_INVALID');
        }

        $origin = 'https://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $allowed = array_values(array_filter(array_map([$this, 'normalizeOrigin'], $this->allowedOrigins)));
        if (!in_array($origin, $allowed, true)) throw new \InvalidArgumentException('EXISTING_MEDIA_URL_HOST_NOT_ALLOWED');

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) throw new \InvalidArgumentException('EXISTING_MEDIA_URL_INVALID');
        $path = rawurldecode($path);
        if (str_contains($path, "\0") || str_contains($path, '\\')) throw new \InvalidArgumentException('EXISTING_MEDIA_URL_PATH_TRAVERSAL');
        $segments = explode('/', trim($path, '/'));
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) throw new \InvalidArgumentException('EXISTING_MEDIA_URL_PATH_TRAVERSAL');

        $basePath = $this->uploadBasePath;
        if ($basePath === null) {
            $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
            $baseUrl = is_array($upload) ? (string) ($upload['baseurl'] ?? '') : '';
            $baseParts = parse_url($baseUrl);
            $basePath = is_array($baseParts) ? rtrim(rawurldecode((string) ($baseParts['path'] ?? '')), '/') : '';
        }
        $basePath = rtrim((string) $basePath, '/');
        if ($basePath === '' || !str_starts_with($path, $basePath . '/')) throw new \InvalidArgumentException('EXISTING_MEDIA_URL_NOT_WORDPRESS_UPLOAD');

        $relative = ltrim(substr($path, strlen($basePath)), '/');
        if ($relative === '' || str_contains($relative, '..')) throw new \InvalidArgumentException('EXISTING_MEDIA_URL_PATH_TRAVERSAL');
        return $relative;
    }

    private function findByAttachedFile(string $relative): array
    {
        if (!method_exists($this->database, 'prepare') || !method_exists($this->database, 'get_col')) return [];
        $posts = $this->database->posts ?? ((string) ($this->database->prefix ?? '') . 'posts');
        $meta = $this->database->postmeta ?? ((string) ($this->database->prefix ?? '') . 'postmeta');
        $sql = "SELECT pm.post_id FROM {$meta} pm INNER JOIN {$posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s";
        $ids = $this->database->get_col($this->database->prepare($sql, '_wp_attached_file', $relative, 'attachment'));
        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }

    private function findByDerivative(string $relative): array
    {
        if (!method_exists($this->database, 'prepare') || !method_exists($this->database, 'get_col') || !function_exists('maybe_unserialize') || !function_exists('get_post_meta')) return [];
        $posts = $this->database->posts ?? ((string) ($this->database->prefix ?? '') . 'posts');
        $meta = $this->database->postmeta ?? ((string) ($this->database->prefix ?? '') . 'postmeta');
        $basename = basename($relative);
        $sql = "SELECT pm.post_id FROM {$meta} pm INNER JOIN {$posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_type = %s";
        $needle = '%' . $this->database->esc_like($basename) . '%';
        $candidates = $this->database->get_col($this->database->prepare($sql, '_wp_attachment_metadata', $needle, 'attachment'));
        $resolved = [];
        foreach (array_values(array_unique(array_map('intval', is_array($candidates) ? $candidates : []))) as $attachmentId) {
            $attached = (string) get_post_meta($attachmentId, '_wp_attached_file', true);
            if ($attached === '') continue;
            $directory = trim(str_replace('\\', '/', dirname($attached)), './');
            $metadata = maybe_unserialize(get_post_meta($attachmentId, '_wp_attachment_metadata', true));
            if (!is_array($metadata)) continue;
            foreach ((array) ($metadata['sizes'] ?? []) as $size) {
                if (!is_array($size) || !isset($size['file'])) continue;
                $candidate = ($directory !== '' ? $directory . '/' : '') . basename((string) $size['file']);
                if ($candidate === $relative) {
                    $resolved[] = $attachmentId;
                    break;
                }
            }
        }
        return array_values(array_unique($resolved));
    }

    private function assertReadableImage(int $attachmentId): void
    {
        if ($attachmentId < 1 || !function_exists('get_post') || !function_exists('get_post_mime_type') || !function_exists('get_attached_file')) throw new \InvalidArgumentException('EXISTING_MEDIA_ATTACHMENT_READBACK_FAILED');
        $post = get_post($attachmentId);
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') throw new \InvalidArgumentException('EXISTING_MEDIA_ATTACHMENT_NOT_FOUND');
        if (!str_starts_with(strtolower((string) get_post_mime_type($attachmentId)), 'image/')) throw new \InvalidArgumentException('EXISTING_MEDIA_ATTACHMENT_NOT_IMAGE');
        $path = get_attached_file($attachmentId, true);
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path) || @getimagesize($path) === false) throw new \InvalidArgumentException('EXISTING_MEDIA_ATTACHMENT_READBACK_FAILED');
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url(trim($origin));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || trim((string) ($parts['host'] ?? '')) === '') return '';
        return 'https://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
