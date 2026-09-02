<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\Media;

/** The single application boundary used by all Media intake adapters. */
final class MediaIngestGateway
{
    public function __construct(private MediaService $service) {}

    /** @param array<string,mixed> $packet */
    public function ingest(array $packet): Media
    {
        return $this->service->ingest(
            (string) ($packet['stable_key'] ?? ''),
            (string) ($packet['name'] ?? ''),
            (string) ($packet['readiness'] ?? 'draft'),
            is_array($packet['provenance'] ?? null) ? $packet['provenance'] : [],
            is_array($packet['assets'] ?? null) ? $packet['assets'] : [],
            is_array($packet['usages'] ?? null) ? $packet['usages'] : [],
        );
    }
}
