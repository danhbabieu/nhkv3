<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\{Media, MediaIngestBatch};

final class MediaBatchIngestService
{
    public function __construct(private MediaIngestGateway $gateway) {}

    /** @param list<array<string,mixed>> $packets @return array{batch:array<string,mixed>,items:list<array<string,mixed>>} */
    public function ingest(string $source, string $uploader, array $defaultContext, array $packets): array
    {
        $batch = MediaIngestBatch::start($source, $uploader, $defaultContext, count($packets));
        $items = [];
        foreach ($packets as $packet) {
            $context = is_array($packet['batch_context'] ?? null) ? array_merge($defaultContext, $packet['batch_context']) : $defaultContext;
            $packet['provenance'] = array_merge(is_array($packet['provenance'] ?? null) ? $packet['provenance'] : [], ['batch_id' => $batch->batchId, 'batch_context' => $context]);
            $media = $this->gateway->ingest($packet);
            $items[] = ['media_id' => $media->canonicalId, 'stable_key' => $media->stableKey, 'batch_context' => $context];
        }
        return ['batch' => (new MediaIngestBatch($batch->batchId, $batch->source, $batch->uploader, $batch->defaultContext, $batch->imageCount, 'completed'))->toArray(), 'items' => $items];
    }
}
