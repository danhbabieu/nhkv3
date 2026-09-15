<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ExternalResearchResult
{
    /** @param list<array<string,mixed>> $items @param list<string> $warnings */
    public function __construct(
        public string $status,
        public array $items = [],
        public string $provider = '',
        public int $budget = 10,
        public array $warnings = [],
    ) {
        if (!in_array($status, ['available', 'unavailable', 'blocked'], true) || $budget < 1 || count($items) > $budget) throw new \InvalidArgumentException('External research result is invalid.');
        foreach ($items as $item) if (!is_array($item)) throw new \InvalidArgumentException('External research items must be structured.');
    }

    public static function unavailable(string $reason = 'EXTERNAL_RESEARCH_RUNTIME_UNAVAILABLE'): self
    {
        return new self('unavailable', [], '', 10, [$reason]);
    }
}
