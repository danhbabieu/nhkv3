<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

/**
 * Explicit boundary for the four user-editable attachment metadata fields.
 *
 * A missing key is deliberately different from an empty string: PATCH callers
 * may omit a field to preserve it, or provide an empty string to clear it.
 * This value object contains no transport, Media or MediaUsage ownership.
 */
final readonly class MediaMetadataContract
{
    public const ABSENT = 'ABSENT';
    public const EMPTY = 'EMPTY';
    public const PROVIDED = 'PROVIDED';

    /** @var list<string> */
    public const FIELDS = ['title', 'alt_text', 'caption', 'description'];

    /** @param array<string,mixed> $values @param array<string,string> $states */
    private function __construct(
        public array $values,
        public array $states,
    ) {}

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $values = [];
        $states = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                $states[$field] = self::ABSENT;
                continue;
            }
            if (!is_string($input[$field])) throw new \InvalidArgumentException('Media metadata fields must be strings.');
            $values[$field] = $input[$field];
            $states[$field] = $input[$field] === '' ? self::EMPTY : self::PROVIDED;
        }
        return new self($values, $states);
    }

    public function state(string $field): string
    {
        if (!in_array($field, self::FIELDS, true)) throw new \InvalidArgumentException('Unknown Media metadata field.');
        return $this->states[$field];
    }

    /** @return array<string,string> Only explicitly supplied fields. */
    public function toArray(): array
    {
        return array_intersect_key($this->values, array_filter($this->states, static fn (string $state): bool => $state !== self::ABSENT));
    }

    /** @return array<string,string> Fields that may be projected to WordPress. */
    public function projection(): array
    {
        return array_intersect_key($this->toArray(), array_flip(self::FIELDS));
    }
}
