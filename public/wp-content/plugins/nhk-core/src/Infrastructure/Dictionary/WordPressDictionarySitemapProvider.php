<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Dictionary;

use NHK\Core\Application\Dictionary\DictionaryPublicQuery;

final class WordPressDictionarySitemapProvider extends \WP_Sitemaps_Provider
{
    public function __construct(private DictionaryPublicQuery $query)
    {
        $this->name = 'dictionary';
        $this->object_type = 'dictionary';
    }

    public function get_url_list($page_num, $object_subtype = ''): array
    {
        if ((int) $page_num !== 1) return [];
        $urls = [['loc' => home_url('/tu-dien/')]];
        foreach ((array) ($this->query->hub(2000)['items'] ?? []) as $item) {
            if (!is_array($item) || ($item['dedicated'] ?? false) !== true || ($item['indexable'] ?? false) !== true || trim((string) ($item['url'] ?? '')) === '') continue;
            $urls[] = ['loc' => preg_match('#^https?://#i', (string) $item['url']) ? (string) $item['url'] : home_url('/' . ltrim((string) $item['url'], '/'))];
        }
        return $urls;
    }

    public function get_max_num_pages($object_subtype = ''): int
    {
        return 1;
    }
}
