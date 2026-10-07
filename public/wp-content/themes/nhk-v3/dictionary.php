<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

$context = is_array($GLOBALS['nhk_core_dictionary_context'] ?? null) ? $GLOBALS['nhk_core_dictionary_context'] : [];
$mode = (string) ($context['mode'] ?? '');
$result = is_array($context['result'] ?? null) ? $context['result'] : [];
$presentation = is_array($result['presentation'] ?? null) ? $result['presentation'] : [];
$search = trim((string) ($result['query'] ?? ''));
$activeInitial = trim((string) ($result['initial'] ?? ''));
$items = array_values(array_filter((array) ($result['items'] ?? []), static fn (mixed $item): bool => is_array($item) && trim((string) ($item['title'] ?? '')) !== '' && trim((string) ($item['url'] ?? '')) !== ''));
$pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
$total = isset($pagination['total_count']) && $pagination['total_count'] !== null ? (int) $pagination['total_count'] : null;
$alphabet = array_values(array_filter((array) ($result['alphabet'] ?? []), static fn (mixed $bucket): bool => is_array($bucket) && trim((string) ($bucket['key'] ?? '')) !== ''));
$groups = [];
foreach ($items as $item) $groups[nhk_v3_dictionary_initial((string) $item['title'])][] = $item;
uksort($groups, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

get_header();
?>
<main id="main-content" class="site-main nhk-dictionary">
    <div class="breadcrumbs" aria-label="Đường dẫn"><ol>
        <li><a href="<?php echo esc_url(home_url('/')); ?>">Trang chủ</a></li>
        <?php if ($mode === 'detail' && $presentation !== []): ?><li><a href="<?php echo esc_url(home_url('/tu-dien/')); ?>">Từ điển</a></li><li><span><?php echo esc_html((string) ($presentation['identity']['title'] ?? '')); ?></span></li>
        <?php else: ?><li><span>Từ điển</span></li><?php endif; ?>
    </ol></div>

<?php if ($mode === 'hub'): ?>
    <header class="archive-intro"><p class="eyebrow">Tra cứu nhanh</p><h1>Từ điển đồng hồ cổ</h1><p class="section-deck">Tra cứu thuật ngữ kỹ thuật, tên gọi quốc tế và cách gọi trong giới sưu tầm đồng hồ.</p>
        <form class="dictionary-search" method="get" action="<?php echo esc_url(home_url('/tu-dien/')); ?>"><label for="dictionary-search-input">Tìm trong từ điển</label><div><input id="dictionary-search-input" name="q" type="search" value="<?php echo esc_attr($search); ?>" placeholder="Tìm thuật ngữ..."><button type="submit">Tra cứu</button></div></form>
    </header>
    <div class="dictionary-browse-summary" role="status" aria-live="polite">
        <?php if ($search !== ''): ?><p>Kết quả cho “<?php echo esc_html($search); ?>”<?php if ($total !== null): ?> · <?php echo esc_html((string) $total); ?> thuật ngữ<?php endif; ?></p>
        <?php elseif ($activeInitial !== '' && $total !== null): ?><p><?php echo esc_html((string) $total); ?> thuật ngữ bắt đầu bằng <?php echo esc_html($activeInitial); ?></p>
        <?php elseif ($total !== null): ?><p><?php echo esc_html((string) $total); ?> thuật ngữ</p><?php else: ?><p>Danh sách thuật ngữ</p><?php endif; ?>
    </div>
    <nav class="dictionary-alphabet" aria-label="Tra cứu theo chữ cái">
        <?php foreach ($alphabet as $bucket): $key = (string) ($bucket['key'] ?? ''); $label = (string) ($bucket['label'] ?? $key); $count = (int) ($bucket['count'] ?? 0); $available = (bool) ($bucket['available'] ?? false); $active = ($activeInitial === '' && $key === 'ALL') || ($activeInitial !== '' && $key === $activeInitial); $href = add_query_arg(['initial' => $key === 'ALL' ? null : $key], home_url('/tu-dien/')); ?>
            <?php if (!$available && !$active): ?><span class="is-disabled" aria-disabled="true" title="Chưa có thuật ngữ"><?php echo esc_html($label); ?></span>
            <?php else: ?><a href="<?php echo esc_url((string) $href); ?>"<?php if ($active): ?> aria-current="true" class="is-active"<?php endif; ?>><?php echo esc_html($label); ?><?php if ($count > 0): ?><small><?php echo esc_html((string) $count); ?></small><?php endif; ?></a><?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php if ($items === []): ?><div class="empty dictionary-empty"><h2><?php echo $search !== '' || $activeInitial !== '' ? 'Chưa có kết quả phù hợp' : 'Từ điển đang được biên tập'; ?></h2><p><?php echo $search !== '' ? 'Hãy thử một cách gọi khác.' : 'Chưa có thuật ngữ đã được phát hành công khai.'; ?></p></div>
    <?php else: ?><div class="dictionary-groups"><?php foreach ($groups as $initial => $group): ?><section id="dictionary-<?php echo esc_attr(nhk_v3_dictionary_anchor((string) $initial)); ?>" class="dictionary-group"><div class="section-head"><div><p class="eyebrow">Nhóm thuật ngữ</p><h2><?php echo esc_html((string) $initial); ?></h2></div></div><div class="dictionary-grid"><?php foreach ($group as $item): ?><article class="dictionary-card"><div class="dictionary-card-body"><p class="eyebrow"><?php echo esc_html(nhk_v3_public_copy((string) ($item['term_type'] ?? 'Thuật ngữ'))); ?></p><h3><a href="<?php echo esc_url(nhk_v3_public_url($item['url'] ?? null)); ?>"><?php echo esc_html((string) $item['title']); ?></a></h3><?php if (trim((string) ($item['description'] ?? '')) !== ''): ?><p><?php echo esc_html(wp_trim_words((string) $item['description'], 24)); ?></p><?php endif; ?></div></article><?php endforeach; ?></div></section><?php endforeach; ?></div>
        <?php if (($pagination['has_next'] ?? false) && trim((string) ($pagination['next_cursor'] ?? '')) !== ''): ?><nav class="dictionary-pagination" aria-label="Phân trang từ điển"><a href="<?php echo esc_url((string) add_query_arg(array_filter(['q' => $search, 'initial' => $activeInitial !== '' ? $activeInitial : null, 'cursor' => (string) $pagination['next_cursor']]), home_url('/tu-dien/'))); ?>">Trang sau <span aria-hidden="true">→</span></a></nav><?php endif; ?>
    <?php endif; ?>
<?php elseif ($mode === 'detail' && $presentation !== []): ?>
    <?php get_template_part('template-parts/dictionary/dictionary-detail', null, ['packet' => $presentation]); ?>
<?php else: ?><div class="empty"><h1>Không thể tải từ điển</h1><p>Trang này hiện chưa sẵn sàng.</p></div><?php endif; ?>
</main>
<?php get_footer(); ?>
