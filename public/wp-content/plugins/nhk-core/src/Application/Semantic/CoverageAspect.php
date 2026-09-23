<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Generic transient reader-coverage aspect. */
final readonly class CoverageAspect
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return ['key' => $this->key, 'label' => $this->label, 'required' => $this->required]; }
}
