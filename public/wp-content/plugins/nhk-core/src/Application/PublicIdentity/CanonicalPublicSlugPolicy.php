<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

/**
 * Canonical public-slug normalization only.
 *
 * This policy never derives or mutates semantic stable keys, UUIDs,
 * idempotency keys, source identifiers or other domain identity.
 */
final class CanonicalPublicSlugPolicy
{
    /** @var array<string,string> */
    private const VIETNAMESE_ASCII = [
        'Đ' => 'D', 'đ' => 'd',
        'À' => 'A', 'Á' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A', 'Ă' => 'A', 'Ằ' => 'A', 'Ắ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A', 'Â' => 'A', 'Ầ' => 'A', 'Ấ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
        'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
        'È' => 'E', 'É' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E', 'Ê' => 'E', 'Ề' => 'E', 'Ế' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
        'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
        'Ì' => 'I', 'Í' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I',
        'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
        'Ò' => 'O', 'Ó' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O', 'Ô' => 'O', 'Ồ' => 'O', 'Ố' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O', 'Ơ' => 'O', 'Ờ' => 'O', 'Ớ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
        'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
        'Ù' => 'U', 'Ú' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U', 'Ư' => 'U', 'Ừ' => 'U', 'Ứ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
        'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
        'Ỳ' => 'Y', 'Ý' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y',
        'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
    ];

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        // Transliteration is deliberately before ASCII sanitization. NFC
        // Vietnamese uses the table; NFD input is covered by removing marks
        // only after the special Vietnamese base characters are normalized.
        $value = strtr($value, self::VIETNAMESE_ASCII);
        $value = (string) preg_replace('/\p{Mn}+/u', '', $value);
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        if ($value === '') return '';

        // Historical reader-facing spelling cleanup remains a public-route
        // concern and never rewrites semantic identity.
        $value = (string) preg_replace('/(^|-)o-do(?=-|$)/', '$1odo', $value);

        // Only a standalone public token is expanded; larger strings such as
        // nhkv3 or abcnhkxyz retain their literal token content.
        $value = (string) preg_replace('/(^|-)nhk(?=-|$)/', '$1nha-kho', $value);

        return trim((string) preg_replace('/-+/', '-', $value), '-');
    }

    /**
     * Build shortest-first public slug candidates from meaningful domain data.
     * Callers remain responsible for checking route-scope availability.
     *
     * @param list<string> $meaningfulSuffixValues
     * @return list<string>
     */
    public static function candidates(string $value, array $meaningfulSuffixValues = []): array
    {
        $base = self::normalize($value);
        if ($base === '') return [];
        $candidates = [$base];
        foreach ($meaningfulSuffixValues as $value) {
            $suffix = self::normalize($value);
            if ($suffix === '') continue;
            $candidate = $base . '-' . $suffix;
            if (!in_array($candidate, $candidates, true)) $candidates[] = $candidate;
        }
        return $candidates;
    }

    public static function isCanonical(string $value): bool
    {
        return $value !== ''
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1
            && self::normalize($value) === $value;
    }
}
