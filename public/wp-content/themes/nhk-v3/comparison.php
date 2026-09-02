<?php
$context = $GLOBALS['nhk_core_comparison_context'] ?? [];
$comparison = is_array($context['comparison'] ?? null) ? $context['comparison'] : [];
$references = is_array($comparison['references'] ?? null) ? $comparison['references'] : ['left' => '', 'right' => ''];
$items = is_array($comparison['items'] ?? null) ? $comparison['items'] : ['left' => null, 'right' => null];
get_header();
?><main id="main-content" class="site-main comparison-shell">
  <header class="archive-intro"><p class="eyebrow">Khám phá NHK</p><h1>So sánh hồ sơ</h1><p class="archive-summary">Đặt hai hồ sơ cạnh nhau để đọc nhanh các dữ kiện đang công khai.</p></header>
  <form class="comparison-form" method="get" action="<?php echo esc_url(home_url('/so-sanh/')); ?>">
    <div><label for="nhk-comparison-a">Hồ sơ A</label><input id="nhk-comparison-a" name="a" type="text" value="<?php echo esc_attr((string) ($references['left'] ?? '')); ?>" placeholder="Ví dụ hồ sơ thương hiệu"></div>
    <div><label for="nhk-comparison-b">Hồ sơ B</label><input id="nhk-comparison-b" name="b" type="text" value="<?php echo esc_attr((string) ($references['right'] ?? '')); ?>" placeholder="Ví dụ hồ sơ mẫu đồng hồ"></div>
    <button type="submit">So sánh</button>
  </form>
  <?php if (!empty($references['left']) || !empty($references['right'])): ?>
    <?php if (is_array($items['left'] ?? null) && is_array($items['right'] ?? null)): ?>
      <section class="compare-grid" aria-label="Kết quả so sánh">
        <?php foreach (['left', 'right'] as $side): $item = $items[$side]; ?>
          <article class="compare-card"><p class="eyebrow"><?php echo esc_html(strtoupper($side)); ?> · <?php echo esc_html(nhk_v3_public_type((string) $item['type'])); ?></p><h2><?php echo esc_html((string) $item['name']); ?></h2><dl class="entity-facts"><?php foreach ($item['payload'] as $key => $value): ?><dt><?php echo esc_html(nhk_v3_public_label((string) $key)); ?></dt><dd><?php echo esc_html(nhk_v3_public_value($value)); ?></dd><?php endforeach; ?></dl></article>
        <?php endforeach; ?>
      </section>
    <?php else: ?><div class="empty"><h2>Chưa đủ hồ sơ để so sánh</h2><p>Chỉ hồ sơ đang hoạt động mới được hiển thị. Nhập đường dẫn hồ sơ đã có trong NHK.</p></div><?php endif; ?>
  <?php else: ?><div class="empty comparison-help"><h2>Bắt đầu từ hai hồ sơ</h2><p>Nhập hai đường dẫn hồ sơ để đọc cạnh nhau.</p></div><?php endif; ?>
</main><?php get_footer();
