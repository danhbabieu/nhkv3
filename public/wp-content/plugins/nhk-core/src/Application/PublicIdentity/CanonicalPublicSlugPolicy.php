<?php
declare(strict_types=1);

namespace NHK\Core\Application\PublicIdentity;

/**
 * Pure public-slug normalization and collision-candidate policy.
 *
 * This class owns text projection only. It never changes semantic identity,
 * stable keys, UUIDs, database IDs, external references or idempotency keys.
 */
final class CanonicalPublicSlugPolicy
{
    /** @var array<string,string> */
    private const VIETNAMESE = [
        'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
        'À'=>'A','Á'=>'A','Ả'=>'A','Ã'=>'A','Ạ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ẳ'=>'A','Ẵ'=>'A','Ặ'=>'A','Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ậ'=>'A',
        'è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
        'È'=>'E','É'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ẹ'=>'E','Ê'=>'E','Ề'=>'E','Ế'=>'E','Ể'=>'E','Ễ'=>'E','Ệ'=>'E',
        'ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
        'Ì'=>'I','Í'=>'I','Ỉ'=>'I','Ĩ'=>'I','Ị'=>'I',
        'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
        'Ò'=>'O','Ó'=>'O','Ỏ'=>'O','Õ'=>'O','Ọ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ổ'=>'O','Ỗ'=>'O','Ộ'=>'O','Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ở'=>'O','Ỡ'=>'O','Ợ'=>'O',
        'ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
        'Ù'=>'U','Ú'=>'U','Ủ'=>'U','Ũ'=>'U','Ụ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ử'=>'U','Ữ'=>'U','Ự'=>'U',
        'ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
        'Ỳ'=>'Y','Ý'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y','Ỵ'=>'Y',
        'đ'=>'d','Đ'=>'D',
    ];

    public function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        // Precomposed Vietnamese is mapped first. NFD combining marks are
        // removed afterwards, so NFC/NFD produce the same ASCII tokens.
        $value = strtr($value, self::VIETNAMESE);
        $withoutMarks = preg_replace('/\p{Mn}+/u', '', $value);
        if (is_string($withoutMarks)) $value = $withoutMarks;

        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string) preg_replace('/-+/', '-', $value), '-');
        if ($value === '') return '';

        // Public-only token semantics. This deliberately does not touch
        // internal strings such as stable keys, UUIDs or identifiers.
        $value = (string) preg_replace('/(^|-)nhk(?=-|$)/', '$1nha-kho', $value);

        // Preserve the established compact public brand token as a semantic
        // cleanup rule in the one shared policy rather than one-off callers.
        $value = (string) preg_replace('/(^|-)o-do(?=-|$)/', '$1odo', $value);

        return trim((string) preg_replace('/-+/', '-', $value), '-');
    }

    /**
     * @param list<string> $meaningfulQualifiers Ordered, public, meaningful qualifiers.
     * @param callable(string):bool $isTaken
     */
    public function resolve(string $value, array $meaningfulQualifiers, callable $isTaken): string
    {
        $base = $this->slug($value);
        if ($base === '') throw new \InvalidArgumentException('PUBLIC_SLUG_INVALID');
        if (!$isTaken($base)) return $base;

        $seen = [$base => true];
        foreach ($meaningfulQualifiers as $qualifier) {
            $suffix = $this->slug((string) $qualifier);
            if ($suffix === '') continue;
            $candidate = $base . '-' . $suffix;
            if (isset($seen[$candidate])) continue;
            $seen[$candidate] = true;
            if (!$isTaken($candidate)) return $candidate;
        }

        throw new \RuntimeException('PUBLIC_SLUG_COLLISION_REQUIRES_RECONCILIATION');
    }
}
