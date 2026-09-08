<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

final readonly class ProjectionRevision
{
    /** @param array<string,mixed> $payload @param list<string> $dirtySections */
    public function __construct(
        public string $nodeUuid,
        public int $revision,
        public string $status,
        public string $inputHash,
        public string $claimSetHash,
        public string $graphHash,
        public int $policyRevision = 1,
        public int $templateRevision = 1,
        public array $payload = [],
        public array $dirtySections = [],
        public string $generatedAt = '',
        public ?string $publishedAt = null,
    ) {
        if (trim($nodeUuid) === '' || $revision < 1 || !ProjectionStatus::isValid($status) || trim($inputHash) === '') throw new \InvalidArgumentException('Projection revision is invalid.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['node_uuid' => $this->nodeUuid, 'projection_revision' => $this->revision, 'status' => $this->status, 'input_hash' => $this->inputHash, 'claim_set_hash' => $this->claimSetHash, 'graph_hash' => $this->graphHash, 'policy_revision' => $this->policyRevision, 'template_revision' => $this->templateRevision, 'payload' => $this->payload, 'dirty_sections' => $this->dirtySections, 'generated_at' => $this->generatedAt, 'published_at' => $this->publishedAt];
    }
}
