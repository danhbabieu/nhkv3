<?php
declare(strict_types=1);

if (!function_exists('wp_raise_memory_limit')) {
    function wp_raise_memory_limit(string $context = 'admin'): string
    {
        return ini_get('memory_limit') ?: '-1';
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_get_image_mime')) {
    function wp_get_image_mime(string $file): string|false
    {
        $info = @getimagesize($file);
        return is_array($info) ? ($info['mime'] ?? false) : false;
    }
}

if (!function_exists('wp_get_mime_types')) {
    function wp_get_mime_types(): array
    {
        return ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    }
}

if (!function_exists('wp_get_default_extension_for_mime_type')) {
    function wp_get_default_extension_for_mime_type(string $mimeType): string|false
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => false,
        };
    }
}

if (!function_exists('wp_filesize')) {
    function wp_filesize(string $path): int
    {
        $size = @filesize($path);
        return $size === false ? 0 : (int) $size;
    }
}

if (!function_exists('wp_is_stream')) {
    function wp_is_stream(string $path): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $path);
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0777, true);
    }
}

if (!function_exists('wp_basename')) {
    function wp_basename(string $path, string $suffix = ''): string
    {
        return basename($path, $suffix);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('wp_fuzzy_number_match')) {
    function wp_fuzzy_number_match(int|float $expected, int|float $actual): bool
    {
        return abs($expected - $actual) <= 1;
    }
}
