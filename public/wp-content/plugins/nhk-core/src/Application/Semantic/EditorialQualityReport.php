<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient quality-gate output; it is diagnostic and never a publication record. */
final readonly class EditorialQualityReport
{
    /** @param array<string,array<string,mixed>> $dimensions @param list<string> $blockers @param list<string> $warnings @param list<string> $informational */
    public function __construct(
        public string $readiness,
        public string $profile,
        public array $dimensions,
        public array $blockers = [],
        public array $warnings = [],
        public array $informational = [],
        public array $diagnostics = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'readiness' => $this->readiness,
            'profile' => $this->profile,
            'dimensions' => $this->dimensions,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'informational' => $this->informational,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
