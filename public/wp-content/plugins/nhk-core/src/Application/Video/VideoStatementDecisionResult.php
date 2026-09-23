<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final readonly class VideoStatementDecisionResult
{
    public function __construct(private array $items, private array $findings) {}
    public function items(): array { return $this->items; }
    public function findings(): array { return $this->findings; }
    public function toArray(): array { return ['items' => $this->items, 'findings' => $this->findings]; }
}
