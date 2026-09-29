<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/**
 * Keeps detector windows lexical without pretending that frequency is proof.
 * The vocabulary is a small Vietnamese grammar boundary, not a domain term list.
 */
final class DictionaryLexicalQualityGate
{
    private const BOUNDARY_WORDS = [
        'mà', 'và', 'hoặc', 'là', 'có', 'cho', 'với', 'của', 'được', 'trong', 'trên',
        'dưới', 'này', 'đó', 'thì', 'khi', 'để', 'từ', 'một', 'những', 'các', 'như',
        'thế', 'nào', 'nằm', 'ở', 'trở', 'nếu', 'vì', 'nên', 'khiến', 'tại', 'bởi',
        'cùng', 'tự', 'thường', 'phổ', 'biến', 'gặp', 'chúng', 'ta', 'họ', 'nó',
    ];

    public function filter(string $phrase): ?string
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        if ($parts === []) return null;

        foreach ($parts as $index => $part) {
            $word = $this->lower((string) preg_replace('/[^\p{L}\p{N}\-]/u', '', $part));
            if ($word !== '' && in_array($word, self::BOUNDARY_WORDS, true)) {
                $parts = array_slice($parts, 0, $index);
                break;
            }
        }

        while ($parts !== []) {
            $last = $this->lower((string) preg_replace('/[^\p{L}\p{N}\-]/u', '', (string) end($parts)));
            if ($last === '' || !in_array($last, self::BOUNDARY_WORDS, true)) break;
            array_pop($parts);
        }

        $result = trim(implode(' ', $parts));
        return $result === '' ? null : $result;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
