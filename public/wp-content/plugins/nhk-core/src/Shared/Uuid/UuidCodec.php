<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Uuid;
use Symfony\Component\Uid\Uuid;
final class UuidCodec {
    public static function newV7(): string { return Uuid::v7()->toRfc4122(); }
    public static function toBinary(string $uuid): string { return Uuid::fromString($uuid)->toBinary(); }
    public static function fromBinary(string $binary): string { return Uuid::fromBinary($binary)->toRfc4122(); }
}
