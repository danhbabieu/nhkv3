<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

final class NativeWordPressAttachmentMetadataPort implements WordPressAttachmentMetadataPort
{
    public function updatePost(int $attachmentId, array $fields): bool
    {
        if (!function_exists('wp_update_post')) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_UNAVAILABLE');
        $result = wp_update_post(['ID' => $attachmentId] + $fields, true);
        return !is_wp_error($result) && (int) $result === $attachmentId;
    }

    public function updateAlt(int $attachmentId, string $altText): bool
    {
        if (!function_exists('update_post_meta')) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_UNAVAILABLE');
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);
        return true;
    }

    public function read(int $attachmentId): ?array
    {
        if (!function_exists('get_post') || !function_exists('get_post_meta')) return null;
        $post = get_post($attachmentId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') return null;
        return [
            'title' => (string) $post->post_title,
            'alt_text' => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            'caption' => (string) $post->post_excerpt,
            'description' => (string) $post->post_content,
        ];
    }
}
