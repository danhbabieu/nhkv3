<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Uuid;
use Symfony\Component\Uid\Uuid;
final class UuidCodec {
    public static function newV7(): string { return Uuid::v7()->toRfc4122(); }
    public static function v5(string $name): string { return Uuid::v5(Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8'), $name)->toRfc4122(); }
    public static function toBinary(string $uuid): string { return Uuid::fromString($uuid)->toBinary(); }
    public static function fromBinary(string $binary): string { return Uuid::fromBinary($binary)->toRfc4122(); }
    public static function isValid(string $uuid): bool { if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) !== 1) return false; try { self::toBinary($uuid); return true; } catch (\Throwable) { return false; } }
}
