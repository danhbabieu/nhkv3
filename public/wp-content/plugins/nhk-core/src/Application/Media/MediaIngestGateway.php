<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\Media;

/** The single application boundary used by all Media intake adapters. */
final class MediaIngestGateway
{
    public function __construct(private MediaService $service, private ?\NHK\Core\Contracts\Media\WordPressArticleMediaAdapter $wordpress = null, private ?MediaFilenameNormalizer $filenameNormalizer = null) {}

    /** @param array<string,mixed> $packet */
    public function ingest(array $packet): Media
    {
        $assetSpecs = $this->normalizeNewUploadAssets($packet);
        $media = $this->service->ingest(
            (string) ($packet['stable_key'] ?? ''),
            (string) ($packet['name'] ?? ''),
            (string) ($packet['readiness'] ?? 'draft'),
            is_array($packet['provenance'] ?? null) ? $packet['provenance'] : [],
            $assetSpecs,
            is_array($packet['usages'] ?? null) ? $packet['usages'] : [],
        );
        if ($this->wordpress !== null) {
            $assets = $this->service->assets($media->canonicalId);
            foreach ($assets as $index => $asset) {
                $spec = is_array($assetSpecs[$index] ?? null) ? $assetSpecs[$index] : [];
                $filePath = trim((string) ($spec['file_path'] ?? ''));
                $requiresAttachment = $filePath !== '' || (int) ($spec['wordpress_attachment_id'] ?? 0) > 0;
                if (!$requiresAttachment) continue;
                $this->wordpress->attachmentForMedia($media, $asset, '', [
                    'file_path' => $filePath,
                    'wordpress_attachment_id' => (int) ($spec['wordpress_attachment_id'] ?? 0),
                    'original_filename' => (string) ($spec['original_filename'] ?? basename($asset->storageKey)),
                    'view' => (string) (($spec['metadata']['view'] ?? $spec['metadata']['detail_type'] ?? 'image')),
                    'filename_suffix' => (string) ($spec['metadata']['filename_suffix'] ?? ''),
                ]);
            }
        }
        return $media;
    }

    /** @param array<string,mixed> $packet @return list<array<string,mixed>> */
    private function normalizeNewUploadAssets(array $packet): array
    {
        $assets = is_array($packet['assets'] ?? null) ? $packet['assets'] : [];
        $normalizer = $this->filenameNormalizer ?? new MediaFilenameNormalizer();
        foreach ($assets as $index => $asset) {
            if (!is_array($asset) || trim((string) ($asset['file_path'] ?? '')) === '') continue;
            $original = (string) ($asset['original_filename'] ?? basename((string) ($asset['storage_key'] ?? '')));
            if (preg_match('/^(IMG|DSC|DSCF|PXL)[-_]?/i', $original) !== 1) continue;
            $metadata = is_array($asset['metadata'] ?? null) ? $asset['metadata'] : [];
            $view = (string) ($metadata['view'] ?? $metadata['detail_type'] ?? 'image');
            $directory = trim(str_replace('\\', '/', dirname((string) ($asset['storage_key'] ?? ''))), './');
            $filename = $normalizer->normalize((string) ($packet['name'] ?? ''), $view, $original, isset($metadata['filename_suffix']) ? (string) $metadata['filename_suffix'] : null);
            $assets[$index]['storage_key'] = ($directory !== '' ? $directory . '/' : '') . $filename;
        }
        return array_values($assets);
    }
}
