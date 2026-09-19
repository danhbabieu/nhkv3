<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/**
 * Transport boundary for physical image ingestion.
 *
 * This class deliberately owns no storage or semantic behavior. Native file
 * parts are passed through, while structured provided-file references are
 * materialized by the injected trusted gateway and then sent through the
 * existing WordPress attachment batch service.
 */
final class ImageIngestEntrypoint
{
    /**
     * @param callable(string,array<string,mixed>,array<string,mixed>,array<int,array<string,mixed>>):array<string,mixed> $upload
     * @param callable(list<array<string,mixed>>):array<string,mixed> $materialize
     */
    public function __construct(
        private $upload,
        private $materialize,
    ) {}

    /**
     * @param array<string,mixed> $metadata
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function ingest(string $idempotencyKey, array $metadata, mixed $provided, array $items = [], bool $nativeMultipart = false): array
    {
        if ($nativeMultipart) {
            if (!$this->isNativeFileBag($provided)) throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
            return ($this->upload)($idempotencyKey, $metadata, $provided, $items);
        }

        $references = $this->structuredReferences($provided);
        $materialized = ($this->materialize)($references);
        try {
            $files = is_array($materialized['files'] ?? null) ? $materialized['files'] : null;
            if ($files === null || !$this->isNativeFileBag($files)) throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
            return ($this->upload)($idempotencyKey, $metadata, $files, $items);
        } finally {
            foreach ((array) ($materialized['temporary_paths'] ?? []) as $path) {
                if (is_string($path) && is_file($path)) @unlink($path);
            }
        }
    }

    private function isNativeFileBag(mixed $value): bool
    {
        if (!is_array($value)) return false;
        if (array_key_exists('tmp_name', $value)) return true;
        foreach ($value as $nested) if ($this->isNativeFileBag($nested)) return true;
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function structuredReferences(mixed $provided): array
    {
        if (!is_array($provided) || $provided === []) throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
        $references = array_is_list($provided) ? $provided : [$provided];
        foreach ($references as $reference) {
            if (!is_array($reference) || array_diff(array_keys($reference), ['download_url', 'file_id', 'mime_type', 'file_name', 'ordinal', 'media']) !== []) throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
            if (!is_string($reference['download_url'] ?? null) || trim($reference['download_url']) === '') throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
            if (!is_string($reference['file_id'] ?? null) || trim($reference['file_id']) === '') throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
            foreach (['mime_type', 'file_name'] as $optional) if (array_key_exists($optional, $reference) && !is_string($reference[$optional])) throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
            if (array_key_exists('ordinal', $reference) && !is_int($reference['ordinal'])) throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
            if (array_key_exists('media', $reference) && !is_array($reference['media'])) throw new \InvalidArgumentException('IMAGE_FILE_INPUT_INVALID');
        }
        return array_values($references);
    }
}
