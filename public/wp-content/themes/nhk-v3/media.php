<?php
$context = $GLOBALS['nhk_core_media_context'] ?? null;
$archive = is_array($context) ? ($context['archive'] ?? []) : [];
$fallback = get_theme_file_uri('/assets/default-archive.svg');
get_header();
?>
<main id="main-content" class="site-main media-video-shell media-library-v2">
  <header class="archive-intro media-library-header">
    <p class="eyebrow">Thư viện hình ảnh</p>
    <h1>Hiện vật qua hình ảnh</h1>
    <p class="archive-summary">Ảnh công khai được lấy trực tiếp từ MediaAsset đã sẵn sàng. Mỗi ảnh là một cửa sổ để đối chiếu hình dáng, chi tiết và dấu nhận diện; hệ thống không tạo trang Media giả chỉ để có liên kết.</p>
  </header>

  <?php if (is_array($context) && is_array($archive) && !empty($archive['items'])): ?>
    <div class="media-masonry library-grid">
      <?php foreach ($archive['items'] as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?>
        <figure class="library-item">
          <div class="library-image"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"<?php if (!empty($item['width'])): ?> width="<?php echo esc_attr((string) $item['width']); ?>"<?php endif; ?><?php if (!empty($item['height'])): ?> height="<?php echo esc_attr((string) $item['height']); ?>"<?php endif; ?>></div>
          <figcaption><span class="eyebrow">Hình ảnh</span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? 'Hình ảnh hiện vật'))); ?></strong></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
    <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?>
      <nav class="entity-pagination" aria-label="Phân trang thư viện"><?php for ($page = 1; $page <= $pages; $page++): $url = home_url('/thu-vien/' . ($page > 1 ? 'page/' . $page . '/' : '')); $current = $page === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $page); ?></a><?php endfor; ?></nav>
    <?php endif; ?>
  <?php else: ?>
    <div class="empty media-empty"><h2>Chưa có hình ảnh công khai</h2><p>Thư viện sẽ hiện ảnh thật ngay khi asset đủ điều kiện. Bố cục các trang khác vẫn dùng ảnh minh họa mặc định khi chưa có ảnh đại diện.</p></div>
  <?php endif; ?>
</main>
<?php get_footer(); ?>
