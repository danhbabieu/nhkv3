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
        $cursor = null;
        do {
            $packet = $this->query->archive(['page_size' => 500, 'cursor' => $cursor]);
            if (!in_array(($packet['status'] ?? ''), ['AVAILABLE', 'EMPTY'], true)) return [];
            foreach ((array) ($packet['items'] ?? []) as $item) {
                if (!is_array($item) || ($item['dedicated'] ?? false) !== true || ($item['indexable'] ?? false) !== true || trim((string) ($item['url'] ?? '')) === '') continue;
                $urls[] = ['loc' => preg_match('#^https?://#i', (string) $item['url']) ? (string) $item['url'] : home_url('/' . ltrim((string) $item['url'], '/'))];
            }
            $cursor = ($packet['pagination']['has_next'] ?? false) === true ? ($packet['pagination']['next_cursor'] ?? null) : null;
        } while ($cursor !== null);
        return $urls;
    }

    public function get_max_num_pages($object_subtype = ''): int
    {
        return 1;
    }
}
