<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient reader-journey read model; it is not a semantic owner. */
final readonly class EditorialPlan
{
    /** @param list<array<string,mixed>> $sections */
    public function __construct(
        public string $status,
        public string $profile,
        public array $primarySubject,
        public string $topic,
        public array $sections,
        public array $inputContext = [],
        public array $visualSupport = [],
        public array $blockers = [],
        public array $diagnostics = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'profile' => $this->profile, 'primary_subject' => $this->primarySubject, 'topic' => $this->topic, 'sections' => $this->sections, 'input_context' => $this->inputContext, 'visual_support' => $this->visualSupport, 'blockers' => $this->blockers, 'diagnostics' => $this->diagnostics];
    }
}
