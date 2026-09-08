<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

final readonly class ProjectionContext
{
    /** @param list<array<string,mixed>> $graphPath */
    public function __construct(
        public string $nodeUuid,
        public string $nodeType,
        public ?string $nodeLabel = null,
        public ?string $relation = null,
        public array $graphPath = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(bool $includePath = false): array
    {
        $result = ['node_uuid' => $this->nodeUuid, 'node_type' => $this->nodeType, 'relation' => $this->relation];
        if ($includePath) $result['path'] = $this->graphPath;
        if ($this->nodeLabel !== null && trim($this->nodeLabel) !== '') $result['node_label'] = $this->nodeLabel;
        return $result;
    }
}
