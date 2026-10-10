<?php
declare(strict_types=1);

namespace NHK\Core\Domain\FacebookAudit;

final readonly class FacebookValue
{
    public const NULL = 'NULL';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const INACCESSIBLE = 'INACCESSIBLE';
    public const KNOWN = 'KNOWN';

    private function __construct(public string $state, public mixed $value) {}

    public static function known(mixed $value): self { return new self(self::KNOWN, $value); }
    public static function nullValue(): self { return new self(self::NULL, null); }
    public static function unavailable(): self { return new self(self::UNAVAILABLE, null); }
    public static function inaccessible(): self { return new self(self::INACCESSIBLE, null); }

    /** @return array{state:string,value:mixed} */
    public function toArray(): array { return ['state' => $this->state, 'value' => $this->value]; }
}
