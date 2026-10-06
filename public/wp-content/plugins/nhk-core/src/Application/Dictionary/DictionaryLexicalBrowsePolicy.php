<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** Read-only lexical browse normalization; public slugs remain a separate policy. */
final class DictionaryLexicalBrowsePolicy
{
    /** @var array<string,string> */
    private const BASES = [
        'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
        'è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
        'ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
        'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
        'ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
        'ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
    ];

    /** @return list<array{key:string,label:string,count:int,available:bool}> */
    public function emptyAlphabet(): array
    {
        $out = [['key' => 'ALL', 'label' => 'Tất cả', 'count' => 0, 'available' => true]];
        foreach (['0–9', ...range('A', 'Z'), 'Đ'] as $key) $out[] = ['key' => $key, 'label' => $key, 'count' => 0, 'available' => false];
        return $out;
    }

    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (class_exists('Normalizer')) $value = (string) \Normalizer::normalize($value, \Normalizer::FORM_C);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public function searchKey(string $value): string
    {
        return (new DictionaryTermNormalizer())->normalize($this->normalize($value));
    }

    public function initial(string $value): string
    {
        $value = $this->normalize($value);
        if ($value === '') return '#';
        preg_match('/^./us', $value, $matches);
        $first = $matches[0] ?? '';
        if ($first === '') return '#';
        if ($first === 'đ') return 'Đ';
        $base = self::BASES[$first] ?? $first;
        $base = function_exists('mb_strtoupper') ? mb_strtoupper($base, 'UTF-8') : strtoupper($base);
        if (preg_match('/^[0-9]$/', $base)) return '0–9';
        if (preg_match('/^[A-Z]$/', $base)) return $base;
        return '#';
    }

    public function sortKey(string $value): string
    {
        $value = $this->normalize($value);
        if ($value === '') return "\xFF";
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($chars as $char) {
            if ($char === 'đ') { $out .= 'd~'; continue; }
            $out .= self::BASES[$char] ?? $char;
        }
        return $out;
    }

    public function normalizeInitial(?string $initial): ?string
    {
        $initial = trim((string) $initial);
        if ($initial === '' || strtoupper($initial) === 'ALL' || $initial === 'Tất cả') return '';
        if ($initial === '0-9' || $initial === '0–9') return '0–9';
        if ($initial === 'Đ' || $initial === 'đ') return 'Đ';
        $base = $this->initial($initial);
        return preg_match('/^[A-Z]$/', $base) ? $base : null;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    public function compare(array $left, array $right): int
    {
        $sort = strcmp((string) ($left['_browse_sort_key'] ?? ''), (string) ($right['_browse_sort_key'] ?? ''));
        if ($sort !== 0) return $sort;
        $label = strcmp($this->searchKey((string) ($left['title'] ?? '')), $this->searchKey((string) ($right['title'] ?? '')));
        if ($label !== 0) return $label;
        return strcmp((string) ($left['entry_id'] ?? $left['concept_id'] ?? ''), (string) ($right['entry_id'] ?? $right['concept_id'] ?? ''));
    }
}
