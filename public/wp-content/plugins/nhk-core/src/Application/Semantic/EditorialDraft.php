<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Internal draft read model with private factual traceability metadata. */
final readonly class EditorialDraft
{
    /** @param list<array<string,mixed>> $claimTrace */
    public function __construct(
        public string $status,
        public string $profile,
        public string $title,
        public string $summary,
        public string $body,
        public array $claimTrace = [],
        public array $diagnostics = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'profile' => $this->profile, 'title' => $this->title, 'summary' => $this->summary, 'body' => $this->body, 'claim_trace' => $this->claimTrace, 'diagnostics' => $this->diagnostics];
    }
}
