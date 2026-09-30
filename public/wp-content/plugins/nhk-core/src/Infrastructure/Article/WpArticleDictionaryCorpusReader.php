<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Article;

use NHK\Core\Application\Dictionary\DictionaryCorpusSourceReader;

/** Bounded native wp_posts reader for the Article corpus; it never writes. */
final class WpArticleDictionaryCorpusReader implements DictionaryCorpusSourceReader
{
    public function __construct(private object $database, private ?WpEditorialStateReader $states = null) {}

    public function page(?string $after, int $limit): array
    {
        if (!isset($this->database->posts) || !method_exists($this->database, 'get_results') || !method_exists($this->database, 'prepare')) throw new \RuntimeException('ARTICLE_CORPUS_UNAVAILABLE');
        $where = "post_type='post' AND post_status IN ('publish','draft','private')";
        $args = [];
        if ($after !== null && ctype_digit($after)) { $where .= ' AND ID > %d'; $args[] = (int) $after; }
        $args[] = $limit + 1;
        $rows = $this->database->get_results($this->database->prepare("SELECT ID,post_status FROM {$this->database->posts} WHERE {$where} ORDER BY ID ASC LIMIT %d", ...$args), ARRAY_A) ?: [];
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $id = (int) ($row['ID'] ?? 0);
            $state = ($this->states ?? new WpEditorialStateReader())->read($id);
            if ($state === null || trim($state->content) === '') continue;
            $items[] = ['source_id' => (string) $id, 'source_family' => 'article:' . $id, 'source_kind' => 'ARTICLE', 'raw_text' => implode("\n", array_filter([$state->title, $state->excerpt, function_exists('wp_strip_all_tags') ? wp_strip_all_tags($state->content) : strip_tags($state->content)])), 'raw_or_derived' => 'RAW', 'lineage' => ['source_family' => 'ARTICLE', 'editorial_context' => $state->status], 'context' => ['post_id' => $id, 'post_status' => $state->status, 'editorial_source' => true], 'locale' => 'vi-VN'];
        }
        return ['items' => $items, 'has_more' => count($rows) > $limit, 'next_cursor' => count($rows) > $limit ? (string) ($rows[$limit - 1]['ID'] ?? '') : null];
    }
}
