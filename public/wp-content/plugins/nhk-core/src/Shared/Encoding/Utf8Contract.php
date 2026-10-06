<?php
declare(strict_types=1);

namespace NHK\Core\Shared\Encoding;

use JsonException;

/** One strict UTF-8 boundary for Capture, persistence and MCP result packets. */
final class Utf8Contract
{
    public static function assertValid(mixed $value, string $producer, string $path = 'payload'): void
    {
        self::walk($value, $producer, $path);
    }

    public static function encode(mixed $value, string $producer, string $path = 'payload'): string
    {
        self::assertValid($value, $producer, $path);
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new Utf8SerializationException($producer, $path, $error);
        }
    }

    /** @return array<string,mixed> */
    public static function decodeArray(string $json, string $producer, string $path = 'payload'): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new Utf8SerializationException($producer, $path, $error);
        }
        if (!is_array($value)) throw new Utf8SerializationException($producer, $path);
        self::assertValid($value, $producer, $path);
        return $value;
    }

    private static function walk(mixed $value, string $producer, string $path): void
    {
        if (is_string($value)) {
            if (!preg_match('//u', $value)) throw new Utf8ValidationException($producer, $path);
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::walk($key, $producer, $path . '.key');
                self::walk($item, $producer, $path . '[' . (is_int($key) ? $key : (string) $key) . ']');
            }
        }
    }
}
