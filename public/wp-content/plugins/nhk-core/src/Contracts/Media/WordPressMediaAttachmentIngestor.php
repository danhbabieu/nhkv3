<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

interface WordPressMediaAttachmentIngestor
{
    /**
     * @param array<string,mixed> $file A trusted WordPress multipart file parameter.
     * @return array<string,mixed>
     */
    public function ingest(array $file, string $filename, string $title, int $maxWidth, int $maxHeight, int $quality): array;

    /** @return array<string,mixed>|null */
    public function read(int $attachmentId): ?array;
}
