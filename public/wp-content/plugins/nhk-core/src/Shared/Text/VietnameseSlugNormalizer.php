<?php
declare(strict_types=1);

namespace NHK\Core\Shared\Text;

final class NormalizedSlugResult
{
    public function __construct(private readonly string $value, private readonly ?string $code = null) {}

    public function isValid(): bool { return $this->code === null; }
    public function value(): string { return $this->value; }
    public function code(): ?string { return $this->code; }
}

final class VietnameseSlugNormalizer
{
    public function __construct(private readonly int $maximumLength)
    {
        if ($maximumLength < 1) {
            throw new \InvalidArgumentException('Maximum length must be positive.');
        }
    }

    public function normalize(string $input): NormalizedSlugResult
    {
        if (trim($input) === '') {
            return new NormalizedSlugResult('', 'EMPTY_INPUT');
        }
        if (@preg_match('//u', $input) !== 1) {
            return new NormalizedSlugResult('', 'UNSUPPORTED_INPUT');
        }

        $mapped = strtr($input, ['Ô Đô' => 'odo', 'ô đô' => 'odo']);
        $mapped = strtr($mapped, self::characterMap());
        $mapped = preg_replace('/[\x{0300}-\x{036f}]/u', '', $mapped) ?? '';
        $mapped = strtolower($mapped);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $mapped) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            return new NormalizedSlugResult('', 'EMPTY_RESULT');
        }
        if (strlen($slug) > $this->maximumLength) {
            return new NormalizedSlugResult($slug, 'TOO_LONG');
        }
        return new NormalizedSlugResult($slug);
    }

    /** @return array<string, string> */
    private static function characterMap(): array
    {
        static $map;
        if ($map !== null) { return $map; }
        $map = [];
        $groups = [
            'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'A' => 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ',
            'e' => 'èéẹẻẽêềếệểễ', 'E' => 'ÈÉẸẺẼÊỀẾỆỂỄ',
            'i' => 'ìíịỉĩ', 'I' => 'ÌÍỊỈĨ',
            'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'O' => 'ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ',
            'u' => 'ùúụủũưừứựửữ', 'U' => 'ÙÚỤỦŨƯỪỨỰỬỮ',
            'y' => 'ỳýỵỷỹ', 'Y' => 'ỲÝỴỶỸ',
            'd' => 'đ', 'D' => 'Đ',
        ];
        foreach ($groups as $ascii => $characters) {
            foreach (preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY) as $character) {
                $map[$character] = $ascii;
            }
        }
        return $map;
    }
}
