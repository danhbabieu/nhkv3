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
    private readonly WordPressAttachmentMetadataPort $port;

    public function __construct(?WordPressAttachmentMetadataPort $port = null)
    {
        $this->port = $port ?? new NativeWordPressAttachmentMetadataPort();
    }

    /** @param array<string,mixed> $metadata @return array<string,string> */
    public function apply(int $attachmentId, array $metadata): array
    {
        if ($attachmentId < 1) throw new \InvalidArgumentException('Attachment identity is invalid.');
        $contract = MediaMetadataContract::fromArray($metadata);

        $fields = [];
        if ($contract->state('title') !== MediaMetadataContract::ABSENT) $fields['post_title'] = $contract->values['title'];
        if ($contract->state('caption') !== MediaMetadataContract::ABSENT) $fields['post_excerpt'] = $contract->values['caption'];
        if ($contract->state('description') !== MediaMetadataContract::ABSENT) $fields['post_content'] = $contract->values['description'];
        if ($fields !== [] && !$this->port->updatePost($attachmentId, $fields)) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_WRITE_FAILED');
        if ($contract->state('alt_text') !== MediaMetadataContract::ABSENT && !$this->port->updateAlt($attachmentId, $contract->values['alt_text'])) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_WRITE_FAILED');
        $readback = $this->read($attachmentId);
        if ($readback === null) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_READBACK_FAILED');
        foreach ($contract->projection() as $field => $expected) if (($readback[$field] ?? null) !== $expected) throw new \RuntimeException('WORDPRESS_ATTACHMENT_METADATA_READBACK_MISMATCH');
        return $readback;
    }

    /** @return array<string,string>|null */
    public function read(int $attachmentId): ?array
    {
        return $attachmentId < 1 ? null : $this->port->read($attachmentId);
    }
}
