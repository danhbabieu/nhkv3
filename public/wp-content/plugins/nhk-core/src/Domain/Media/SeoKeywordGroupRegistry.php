<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use InvalidArgumentException;

final class SeoKeywordGroupRegistry
{
    /** @return array<string,array{label:string,synonyms:list<string>}> */
    public static function all(): array
    {
        return [
            'subject' => ['label' => 'Chủ thể', 'synonyms' => ['đối tượng']],
            'brand_context' => ['label' => 'Ngữ cảnh thương hiệu', 'synonyms' => ['thương hiệu']],
            'model_variant_context' => ['label' => 'Ngữ cảnh mẫu/biến thể', 'synonyms' => ['mẫu', 'biến thể']],
            'view' => ['label' => 'Góc nhìn', 'synonyms' => ['mặt trước', 'mặt sau']],
            'part' => ['label' => 'Bộ phận', 'synonyms' => ['chi tiết']],
            'content_intent' => ['label' => 'Mục đích nội dung', 'synonyms' => ['nhận diện', 'tham khảo']],
            'evidence_type' => ['label' => 'Loại bằng chứng', 'synonyms' => ['số serial', 'logo']],
        ];
    }

    public static function assertKnown(string $group): void
    {
        if (!array_key_exists($group, self::all())) throw new InvalidArgumentException('Unknown SEO keyword group: ' . $group);
    }
}
