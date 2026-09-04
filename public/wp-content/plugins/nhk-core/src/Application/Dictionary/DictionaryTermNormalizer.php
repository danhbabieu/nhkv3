<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryTermNormalizer
{
    private const VI_UPPER = ['À'=>'à','Á'=>'á','Ạ'=>'ạ','Ả'=>'ả','Ã'=>'ã','Â'=>'â','Ầ'=>'ầ','Ấ'=>'ấ','Ậ'=>'ậ','Ẩ'=>'ẩ','Ẫ'=>'ẫ','Ă'=>'ă','Ằ'=>'ằ','Ắ'=>'ắ','Ặ'=>'ặ','Ẳ'=>'ẳ','Ẵ'=>'ẵ','È'=>'è','É'=>'é','Ẹ'=>'ẹ','Ẻ'=>'ẻ','Ẽ'=>'ẽ','Ê'=>'ê','Ề'=>'ề','Ế'=>'ế','Ệ'=>'ệ','Ể'=>'ể','Ễ'=>'ễ','Ì'=>'ì','Í'=>'í','Ị'=>'ị','Ỉ'=>'ỉ','Ĩ'=>'ĩ','Ò'=>'ò','Ó'=>'ó','Ọ'=>'ọ','Ỏ'=>'ỏ','Õ'=>'õ','Ô'=>'ô','Ồ'=>'ồ','Ố'=>'ố','Ộ'=>'ộ','Ổ'=>'ổ','Ỗ'=>'ỗ','Ơ'=>'ơ','Ờ'=>'ờ','Ớ'=>'ớ','Ợ'=>'ợ','Ở'=>'ở','Ỡ'=>'ỡ','Ù'=>'ù','Ú'=>'ú','Ụ'=>'ụ','Ủ'=>'ủ','Ũ'=>'ũ','Ư'=>'ư','Ừ'=>'ừ','Ứ'=>'ứ','Ự'=>'ự','Ử'=>'ử','Ữ'=>'ữ','Ỳ'=>'ỳ','Ý'=>'ý','Ỵ'=>'ỵ','Ỷ'=>'ỷ','Ỹ'=>'ỹ','Đ'=>'đ'];

    public function normalize(string $term): string
    {
        $term = trim($term);
        if ($term === '') return '';
        $term = preg_replace('/[\x{2010}-\x{2015}]+/u', '-', $term) ?? $term;
        $term = preg_replace('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', "'", $term) ?? $term;
        $term = preg_replace('/\s+/u', ' ', $term) ?? $term;
        $term = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower(strtr($term, self::VI_UPPER));
        return trim($term);
    }
}
