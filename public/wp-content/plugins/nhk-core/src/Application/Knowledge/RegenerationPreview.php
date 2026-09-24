<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

/** Bounded, deterministic diff between current canonical owner material and a candidate. */
final readonly class RegenerationPreview
{
    public function __construct(private array $data) {}
    public function toArray(): array { return $this->data; }
}
