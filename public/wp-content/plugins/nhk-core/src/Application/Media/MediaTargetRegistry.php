<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Graph\EndpointTypeRegistry;

/** Adapter over the existing endpoint registry; it owns no second allow-list. */
final class MediaTargetRegistry
{
    public function __construct(private EndpointTypeRegistry $endpoints) {}

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->endpoints->all());
    }

    public function resolver(string $type): \NHK\Core\Contracts\Graph\EndpointResolver
    {
        return $this->endpoints->resolver($type);
    }
}
