<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
$context = $GLOBALS['nhk_core_dictionary_context'] ?? [];
$mode = is_array($context) ? (string) ($context['mode'] ?? '') : '';
$result = is_array($context['result'] ?? null) ? $context['result'] : [];
get_header();
?>
<main id="primary" class="site-main nhk-dictionary">
<?php if ($mode === 'hub'): ?>
    <header class="nhk-dictionary__header">
        <h1>Từ điển đồng hồ cổ</h1>
        <p>Tra cứu thuật ngữ kỹ thuật, cách gọi dân gian Việt Nam, tên giới thợ và các khái niệm liên quan.</p>
    </header>
    <nav class="nhk-dictionary__az" aria-label="Tra cứu theo chữ cái">
        <?php foreach (range('A', 'Z') as $letter): ?><a href="#dictionary-<?php echo esc_attr(strtolower($letter)); ?>"><?php echo esc_html($letter); ?></a><?php endforeach; ?>
    </nav>
    <div class="nhk-dictionary__list">
        <?php foreach ((array) ($result['items'] ?? []) as $item): if (!is_array($item) || empty($item['url'])) continue; ?>
            <article class="nhk-dictionary-card">
                <?php if (is_array($item['image'] ?? null) && !empty($item['image']['url'])): ?>
                    <a href="<?php echo esc_url($item['url']); ?>"><img loading="lazy" src="<?php echo esc_url($item['image']['url']); ?>" alt="<?php echo esc_attr((string) ($item['image']['alt'] ?? $item['title'] ?? '')); ?>"></a>
                <?php endif; ?>
                <div>
                    <p class="nhk-dictionary-card__type"><?php echo esc_html((string) ($item['term_type'] ?? '')); ?></p>
                    <h2><a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html((string) ($item['title'] ?? '')); ?></a></h2>
                    <p><?php echo esc_html((string) ($item['description'] ?? '')); ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php elseif ($mode === 'detail' && is_array($result['item'] ?? null)): $item = $result['item']; ?>
    <article class="nhk-dictionary-entry">
        <p class="nhk-dictionary-entry__type"><?php echo esc_html((string) ($item['term_type'] ?? '')); ?></p>
        <h1><?php echo esc_html((string) ($item['title'] ?? '')); ?></h1>
        <?php if (is_array($item['image'] ?? null) && !empty($item['image']['url'])): ?><img src="<?php echo esc_url($item['image']['url']); ?>" alt="<?php echo esc_attr((string) ($item['image']['alt'] ?? $item['title'] ?? '')); ?>"><?php endif; ?>
        <p class="nhk-dictionary-entry__definition"><?php echo esc_html((string) ($item['description'] ?? '')); ?></p>
        <?php $aliases = array_values(array_filter((array) ($item['labels'] ?? []), static fn ($label): bool => is_array($label) && ($label['kind'] ?? '') !== 'PREFERRED' && ($label['kind'] ?? '') !== 'HIDDEN')); ?>
        <?php if ($aliases !== []): ?><section><h2>Cách gọi khác</h2><ul><?php foreach ($aliases as $label): ?><li><?php echo esc_html((string) ($label['label'] ?? '')); ?><?php if (!empty($label['kind'])): ?> <small>(<?php echo esc_html((string) $label['kind']); ?>)</small><?php endif; ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        <?php if (!empty($item['usage_scope'])): ?><section><h2>Phạm vi sử dụng</h2><p><?php echo esc_html(implode(', ', array_map('strval', (array) $item['usage_scope']))); ?></p></section><?php endif; ?>
    </article>
<?php endif; ?>
</main>
<?php get_footer();
