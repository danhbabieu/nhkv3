<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

final class MediaFilenameNormalizer
{
    public function normalize(string $subject, string $view, string $originalFilename, ?string $uniqueSuffix = null): string
    {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $extension = preg_match('/^(jpe?g|png|webp|avif|gif)$/', $extension) === 1 ? $extension : 'jpg';
        $subject = $this->slug($subject);
        $view = $view === '' ? '' : $this->slug($view);
        $suffix = $this->slug((string) ($uniqueSuffix ?? substr(hash('sha256', $subject . '|' . $view . '|' . $originalFilename), 0, 8)));
        return trim($subject . '-' . $view . '-' . $suffix, '-') . '.' . $extension;
    }

    public function normalizeWebp(string $subject, string $view, string $context, ?string $uniqueSuffix = null): string
    {
        $subject = $this->slug($subject);
        $view = $view === '' ? '' : $this->slug($view);
        $parts = [$subject];
        if ($view !== '' && $view !== 'image') $parts[] = $view;
        if ($uniqueSuffix !== null && $uniqueSuffix !== '') $parts[] = $this->slug($uniqueSuffix);
        return trim(implode('-', $parts), '-') . '.webp';
    }

    private function slug(string $value): string
    {
        $value = strtr($value, ['à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a','è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e','ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i','ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o','ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u','ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d','À'=>'A','Á'=>'A','Ạ'=>'A','Ả'=>'A','Ã'=>'A','Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ậ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ặ'=>'A','Ẳ'=>'A','Ẵ'=>'A','È'=>'E','É'=>'E','Ẹ'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ê'=>'E','Ề'=>'E','Ế'=>'E','Ệ'=>'E','Ể'=>'E','Ễ'=>'E','Ì'=>'I','Í'=>'I','Ị'=>'I','Ỉ'=>'I','Ĩ'=>'I','Ò'=>'O','Ó'=>'O','Ọ'=>'O','Ỏ'=>'O','Õ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ộ'=>'O','Ổ'=>'O','Ỗ'=>'O','Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ợ'=>'O','Ở'=>'O','Ỡ'=>'O','Ù'=>'U','Ú'=>'U','Ụ'=>'U','Ủ'=>'U','Ũ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ự'=>'U','Ử'=>'U','Ữ'=>'U','Ỳ'=>'Y','Ý'=>'Y','Ỵ'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y','Đ'=>'D']);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value));
        return trim($value, '-') ?: 'media';
    }
}
