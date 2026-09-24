<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient shared input packet; it is never canonical semantic storage. */
final readonly class SemanticInputEnvelope
{
    private function __construct(private UniversalInputEnvelope $universal)
    {
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(UniversalInputEnvelope::fromArray($input));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $value = $this->universal->toArray();
        $value['input_type'] = $value['owner_or_source_type'];
        return $value;
    }

    public function toUniversal(): UniversalInputEnvelope
    {
        return $this->universal;
    }
}
