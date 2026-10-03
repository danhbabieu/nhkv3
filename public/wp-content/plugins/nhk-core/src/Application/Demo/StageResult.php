<?php
declare(strict_types=1);

namespace NHK\Core\Application\Demo;

final readonly class StageResult
{
    /** @param array<string,mixed> $metadata */
    private function __construct(public string $status, public ?string $reasonCode = null, public ?string $identifier = null, public ?string $fingerprint = null, public array $metadata = []) {}
    /** @param array<string,mixed> $metadata */
    public static function pass(?string $identifier = null, ?string $fingerprint = null, array $metadata = []): self { return new self('pass', null, $identifier, $fingerprint, $metadata); }
    public static function blocked(string $reasonCode): self { return new self('blocked', $reasonCode); }
    public static function failed(string $reasonCode): self { return new self('failed', $reasonCode); }
    public function isPass(): bool { return $this->status === 'pass'; }
}
