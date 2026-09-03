<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Application\Media\MediaFilenameNormalizer;
use NHK\Core\Contracts\Media\WordPressMediaAttachmentIngestor as WordPressMediaAttachmentIngestorContract;

/**
 * WordPress-only binary adapter for MCP file attachments.
 *
 * It deliberately does not create NHK Media, Knowledge, Evidence or Graph
 * records. The input attachment is copied to a private work file, normalized,
 * and only the processed output is sent to the WordPress Media Library.
 */
final class WordPressMediaAttachmentIngestor implements WordPressMediaAttachmentIngestorContract
{
    public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array
    {
        if (!function_exists('wp_get_image_editor') || !function_exists('wp_upload_bits') || !function_exists('wp_insert_attachment')) {
            throw new \RuntimeException('WORDPRESS_MEDIA_INGEST_UNAVAILABLE');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('File attachment upload failed.');
        $source = (string) ($file['tmp_name'] ?? '');
        if ($source === '' || !is_file($source) || !is_readable($source)) throw new \InvalidArgumentException('File attachment is unavailable.');
        if ($maxWidth < 1 || $maxHeight < 1) throw new \InvalidArgumentException('max_width and max_height must be positive.');
        if ($quality < 1 || $quality > 100) throw new \InvalidArgumentException('quality must be between 1 and 100.');
        // The managed primary profile is fixed: at most 1600px on either edge.
        // Caller-supplied limits cannot turn the primary into a thumbnail.
        $maxWidth = 1600;
        $maxHeight = 1600;

            $safeFilename = $this->safeFilename($filename, $title, $source);
        $work = function_exists('wp_tempnam') ? wp_tempnam($safeFilename) : tempnam(sys_get_temp_dir(), 'nhk-media-');
        $processed = function_exists('wp_tempnam') ? wp_tempnam($safeFilename) : tempnam(sys_get_temp_dir(), 'nhk-media-');
        if (!is_string($work) || $work === '' || !is_string($processed) || $processed === '') throw new \RuntimeException('WORDPRESS_MEDIA_WORKFILE_UNAVAILABLE');
        $uploadedPath = null;
        $processedPath = null;
        try {
            if (!copy($source, $work)) throw new \RuntimeException('WORDPRESS_MEDIA_WORKFILE_COPY_FAILED');
            $sourceInfo = @getimagesize($work);
            if (!is_array($sourceInfo) || !is_string($sourceInfo['mime'] ?? null)) throw new \InvalidArgumentException('File attachment is not a supported image.');
            $this->allowedMime((string) $sourceInfo['mime']);

            // EXIF orientation is applied before dimensions are measured and
            // before the aspect-preserving resize.
            if (function_exists('maybe_exif_rotate')) {
                $rotated = maybe_exif_rotate($work);
                if (is_wp_error($rotated)) throw new \RuntimeException('WORDPRESS_MEDIA_AUTO_ORIENT_FAILED');
            }
            $editor = wp_get_image_editor($work);
            if (is_wp_error($editor)) throw new \RuntimeException('WORDPRESS_MEDIA_EDITOR_UNAVAILABLE');
            $size = method_exists($editor, 'get_size') ? $editor->get_size() : [];
            $width = (int) ($size['width'] ?? 0);
            $height = (int) ($size['height'] ?? 0);
            if ($width < 1 || $height < 1) throw new \InvalidArgumentException('Image dimensions are unavailable.');
            if ($width > $maxWidth || $height > $maxHeight) {
                $resized = $editor->resize($maxWidth, $maxHeight, false);
                if (is_wp_error($resized)) throw new \RuntimeException('WORDPRESS_MEDIA_RESIZE_FAILED');
            }
            if ($editor->set_quality($quality) === false) throw new \RuntimeException('WORDPRESS_MEDIA_QUALITY_FAILED');
            // WebP is the only durable format for this managed image path.
            // Never pass the source MIME through as a persistence fallback.
            $saved = $editor->save($processed, 'image/webp');
            if (is_wp_error($saved) || !is_array($saved) || !is_string($saved['path'] ?? null)) throw new \RuntimeException('WORDPRESS_MEDIA_PROCESS_FAILED');
            $processedPath = (string) $saved['path'];
            $processedMime = strtolower((string) ($saved['mime-type'] ?? ''));
            if ($processedMime !== 'image/webp') throw new \RuntimeException('WORDPRESS_MEDIA_WEBP_UNAVAILABLE');
            $processedInfo = @getimagesize($processedPath);
            if (!is_array($processedInfo)) throw new \RuntimeException('WORDPRESS_MEDIA_PROCESSED_IMAGE_INVALID');
            $width = (int) ($processedInfo[0] ?? 0);
            $height = (int) ($processedInfo[1] ?? 0);
            $contents = file_get_contents($processedPath);
            if (!is_string($contents)) throw new \RuntimeException('WORDPRESS_MEDIA_PROCESSED_READ_FAILED');

            $upload = wp_upload_bits($safeFilename, null, $contents);
            if (!is_array($upload) || !empty($upload['error']) || !is_string($upload['file'] ?? null)) throw new \RuntimeException('WORDPRESS_MEDIA_UPLOAD_FAILED');
            $uploadedPath = (string) $upload['file'];
            require_once ABSPATH . 'wp-admin/includes/image.php';
            WordPressMediaAttachmentWriteGuard::enter();
            try {
                $attachmentId = wp_insert_attachment([
                    'post_mime_type' => $processedMime,
                    'post_title' => $title !== '' ? $title : pathinfo($safeFilename, PATHINFO_FILENAME),
                    'post_content' => '',
                    'post_status' => 'inherit',
                ], $uploadedPath, 0, true);
            } finally {
                WordPressMediaAttachmentWriteGuard::leave();
            }
            if (is_wp_error($attachmentId) || (int) $attachmentId < 1) throw new \RuntimeException('WORDPRESS_ATTACHMENT_CREATE_FAILED');
            WordPressMediaAttachmentWriteGuard::enter();
            try {
                // Do not invoke wp_generate_attachment_metadata here: its
                // global intermediate-size policy can create an unbounded
                // number of physical files. This scoped ingest stores one
                // verified primary; derivatives are opt-in future contracts.
                $metadata = ['width' => $width, 'height' => $height, 'file' => $this->relativeUploadPath($uploadedPath), 'sizes' => []];
                if (!function_exists('wp_update_attachment_metadata') || wp_update_attachment_metadata((int) $attachmentId, $metadata) === false) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_WRITE_FAILED');
            } finally {
                WordPressMediaAttachmentWriteGuard::leave();
            }
            $result = $this->read((int) $attachmentId);
            if ($result === null) throw new \RuntimeException('WORDPRESS_ATTACHMENT_READBACK_FAILED');
            return $result;
        } finally {
            if (is_string($work) && is_file($work)) @unlink($work);
            if (is_string($processed) && is_file($processed)) @unlink($processed);
            if (is_string($processedPath) && $processedPath !== $processed && is_file($processedPath)) @unlink($processedPath);
            // The original ChatGPT upload is never moved into uploads. Only
            // the sanitized processed file may remain in WordPress storage.
            if ($uploadedPath !== null && !isset($attachmentId) && is_file($uploadedPath)) @unlink($uploadedPath);
        }
    }

    public function read(int $attachmentId): ?array
    {
        if ($attachmentId < 1 || !function_exists('get_post') || !function_exists('get_post_meta')) return null;
        $post = get_post($attachmentId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') return null;
        $status = function_exists('get_post_status') ? (string) get_post_status($attachmentId) : (string) ($post->post_status ?? '');
        if (in_array($status, ['trash', 'private', 'draft', 'pending'], true)) return null;
        $mime = function_exists('get_post_mime_type') ? (string) get_post_mime_type($attachmentId) : '';
        if (!str_starts_with(strtolower($mime), 'image/')) return null;
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $baseDir = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        $baseUrl = is_array($upload) ? (string) ($upload['baseurl'] ?? '') : '';
        $relative = (string) get_post_meta($attachmentId, '_wp_attached_file', true);
        $path = $baseDir !== '' && $relative !== '' ? $baseDir . '/' . ltrim($relative, '/') : '';
        $baseReal = $baseDir !== '' ? realpath($baseDir) : false;
        $pathReal = $path !== '' ? realpath($path) : false;
        if ($baseReal === false || $pathReal === false || !is_file($pathReal) || !$this->within($baseReal, $pathReal)) return null;
        $metadata = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachmentId) : [];
        $metadata = is_array($metadata) ? $metadata : [];
        $filename = basename($relative);
        if ($filename === '') return null;
        $canonicalPath = (new \NHK\Core\Application\Media\PublicMediaAssetUrlResolver())->path($filename);
        $canonicalUrl = function_exists('home_url') ? (string) home_url($canonicalPath) : $canonicalPath;
        $result = [
            'attachment_id' => $attachmentId,
            'canonical_url' => $canonicalUrl,
            'filename' => $filename,
            'mime' => $mime,
            'width' => (int) ($metadata['width'] ?? 0),
            'height' => (int) ($metadata['height'] ?? 0),
            'filesize' => (int) filesize($pathReal),
            'derivatives' => [],
        ];
        foreach ((array) ($metadata['sizes'] ?? []) as $sizeName => $derivative) {
            if (!is_array($derivative) || !isset($derivative['file'])) continue;
            $derivativeFile = (string) $derivative['file'];
            $derivativeRelative = trim(str_replace('\\', '/', dirname($relative)), './');
            $derivativeRelative = ($derivativeRelative !== '' ? $derivativeRelative . '/' : '') . $derivativeFile;
            $derivativePath = $baseDir !== '' ? $baseDir . '/' . ltrim($derivativeRelative, '/') : '';
            $derivativeReal = $derivativePath !== '' ? realpath($derivativePath) : false;
            $result['derivatives'][] = [
                'size' => (string) $sizeName,
                'filename' => basename($derivativeFile),
                'canonical_url' => function_exists('home_url') ? (string) home_url((new \NHK\Core\Application\Media\PublicMediaAssetUrlResolver())->path((string) $derivative['file'])) : (new \NHK\Core\Application\Media\PublicMediaAssetUrlResolver())->path((string) $derivative['file']),
                'mime' => (string) ($derivative['mime-type'] ?? $mime),
                'width' => (int) ($derivative['width'] ?? 0),
                'height' => (int) ($derivative['height'] ?? 0),
                'filesize' => $derivativeReal !== false && is_file($derivativeReal) && $this->within($baseReal, $derivativeReal) ? (int) filesize($derivativeReal) : 0,
            ];
        }
        return $result;
    }

    private function safeFilename(string $filename, string $title, string $source): string
    {
        $filename = trim(str_replace('\\', '/', $filename));
        $filename = basename($filename);
        if ($filename === '') throw new \InvalidArgumentException('filename is required.');
        $info = @getimagesize($source);
        if (!is_array($info) || !is_string($info['mime'] ?? null)) throw new \InvalidArgumentException('File attachment is not a supported image.');
        if (trim($title) === '') throw new \InvalidArgumentException('Trustworthy filename context is required.');
        $checksum = hash_file('sha256', $source);
        if (!is_string($checksum) || $checksum === '') throw new \RuntimeException('WORDPRESS_MEDIA_CHECKSUM_FAILED');
        return (new MediaFilenameNormalizer())->normalizeWebp($title, 'image', $filename);
    }

    private function relativeUploadPath(string $path): string
    {
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = is_array($upload) ? realpath((string) ($upload['basedir'] ?? '')) : false;
        $real = realpath($path);
        if ($base === false || $real === false || !$this->within($base, $real)) throw new \RuntimeException('WORDPRESS_MEDIA_UPLOAD_PATH_INVALID');
        return ltrim(str_replace('\\', '/', substr($real, strlen($base))), '/');
    }

    private function allowedMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) throw new \InvalidArgumentException('Only JPEG, PNG, GIF and WebP images are supported.');
        return $mime;
    }

    private function within(string $root, string $path): bool
    {
        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
