<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

final readonly class ProjectionSection
{
    /** @param list<array<string,mixed>> $claims @param list<array<string,mixed>> $subtopics */
    public function __construct(
        public string $key,
        public string $label,
        public array $claims = [],
        public array $subtopics = [],
        public ?string $inputHash = null,
        public bool $dirty = false,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'claim_count' => count($this->claims), 'subtopics' => $this->subtopics, 'claims' => $this->claims, 'input_hash' => $this->inputHash, 'dirty' => $this->dirty];
    }
}
