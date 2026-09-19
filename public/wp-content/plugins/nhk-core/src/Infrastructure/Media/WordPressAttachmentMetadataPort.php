<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

interface WordPressAttachmentMetadataPort
{
    /** @param array<string,string> $fields */
    public function updatePost(int $attachmentId, array $fields): bool;

    public function updateAlt(int $attachmentId, string $altText): bool;

    /** @return array<string,string>|null */
    public function read(int $attachmentId): ?array;
}
