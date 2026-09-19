<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Domain\Media\MediaMetadataContract;

/**
 * Canonical attachment projection/read-back boundary. WordPress is only the
 * projection here; canonical Media and contextual MediaUsage remain separate.
 */
final class WordPressAttachmentMetadataProjection
{
    /** @param array<string,mixed> $metadata @return array<string,string> */
    public function apply(int $attachmentId, array $metadata): array
    {
        if ($attachmentId < 1) throw new \InvalidArgumentException('Attachment identity is invalid.');
        $contract = MediaMetadataContract::fromArray($metadata);
        if (!function_exists('wp_update_post') || !function_exists('update_post_meta')) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_UNAVAILABLE');

        $fields = [];
        if ($contract->state('title') !== MediaMetadataContract::ABSENT) $fields['post_title'] = $contract->values['title'];
        if ($contract->state('caption') !== MediaMetadataContract::ABSENT) $fields['post_excerpt'] = $contract->values['caption'];
        if ($contract->state('description') !== MediaMetadataContract::ABSENT) $fields['post_content'] = $contract->values['description'];
        if ($fields !== []) {
            $fields['ID'] = $attachmentId;
            $updated = wp_update_post($fields, true);
            if (is_wp_error($updated) || (int) $updated !== $attachmentId) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_WRITE_FAILED');
        }
        if ($contract->state('alt_text') !== MediaMetadataContract::ABSENT) update_post_meta($attachmentId, '_wp_attachment_image_alt', $contract->values['alt_text']);
        $readback = $this->read($attachmentId);
        if ($readback === null) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_READBACK_FAILED');
        foreach ($contract->projection() as $field => $expected) if (($readback[$field] ?? null) !== $expected) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_READBACK_MISMATCH');
        return $readback;
    }

    /** @return array<string,string>|null */
    public function read(int $attachmentId): ?array
    {
        if ($attachmentId < 1 || !function_exists('get_post') || !function_exists('get_post_meta')) return null;
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
