<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference};

final class RelationRevisionBinder
{
    public function __construct(private EndpointTypeRegistry $endpoints) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function bind(array $payload): array
    {
        $source = $this->revision($payload, 'source_type', 'source_uuid');
        $target = $this->revision($payload, 'target_type', 'target_uuid');
        $payload['source_revision'] = $source;
        $payload['target_revision'] = $target;
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function revision(array $payload, string $typeKey, string $uuidKey): int
    {
        $type = trim((string) ($payload[$typeKey] ?? ''));
        $uuid = trim((string) ($payload[$uuidKey] ?? ''));
        if ($type === '' || $uuid === '') throw new \InvalidArgumentException('Relation endpoint identity is required.');
        $resolver = $this->endpoints->resolver($type);
        if (!$resolver instanceof EndpointRevisionReader) throw new \RuntimeException('Relation endpoint revision is unavailable: ' . $type);
        $reference = $this->endpoints->assertExists(new NodeReference($type, $uuid));
        $revision = $resolver->revision($reference);
        if ($revision === null || $revision < 1) throw new \RuntimeException('Relation endpoint revision is unavailable: ' . $reference->key());
        return $revision;
    }
}
