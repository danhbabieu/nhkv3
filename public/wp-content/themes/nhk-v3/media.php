<?php
/* public seo_projection supplies canonical link results; this template never invents semantic ownership. */
$context = $GLOBALS['nhk_core_media_context'] ?? null;
$archive = is_array($context) ? ($context['archive'] ?? []) : [];
$media = is_array($context) ? ($context['media'] ?? []) : [];
$fallback = get_theme_file_uri('/assets/default-archive.svg');
get_header();
?>
<main id="main-content" class="site-main media-video-shell media-library-v2">
<?php if (is_array($context) && ($context['mode'] ?? '') === 'detail' && $media !== []): ?>
  <p class="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">NHK</a> <span>/</span> <a href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Thư viện hình ảnh</a></p>
  <header class="archive-intro media-library-header"><p class="eyebrow">Hình ảnh</p><h1><?php echo esc_html(nhk_v3_public_brand_text((string) ($media['name'] ?? 'Hình ảnh hiện vật'))); ?></h1><p class="archive-summary">Các phiên bản ảnh công khai đã đủ điều kiện hiển thị.</p></header>
  <?php $displayAssets = is_array($media['display_assets'] ?? null) ? $media['display_assets'] : []; if ($displayAssets !== []): ?>
    <div class="media-asset-grid media-detail-gallery">
      <?php foreach ($displayAssets as $asset): $isImage = str_starts_with(strtolower((string) ($asset['mime_type'] ?? '')), 'image/'); $publicUrl = trim((string) ($asset['public_url'] ?? '')); ?>
        <article class="media-asset visual-media-detail">
          <?php if ($isImage && $publicUrl !== ''): ?><figure><img src="<?php echo esc_url(home_url((string) $asset['public_url'])); ?>" alt="<?php echo esc_attr((string) ($media['name'] ?? 'Hình ảnh hiện vật')); ?>" loading="lazy"<?php if (!empty($asset['width'])): ?> width="<?php echo esc_attr((string) $asset['width']); ?>"<?php endif; ?><?php if (!empty($asset['height'])): ?> height="<?php echo esc_attr((string) $asset['height']); ?>"<?php endif; ?>></figure><?php else: ?><figure><img src="<?php echo esc_url($fallback); ?>" alt="" loading="lazy" width="1200" height="750"></figure><?php endif; ?>
          <div><span class="eyebrow">Tài nguyên hình ảnh</span><?php if (!empty($asset['width']) || !empty($asset['height'])): ?><p><?php echo esc_html((string) ($asset['width'] ?? '—')); ?> × <?php echo esc_html((string) ($asset['height'] ?? '—')); ?></p><?php endif; ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?><div class="empty media-empty"><h2>Chưa có ảnh sẵn sàng</h2><p>Hồ sơ này hiện chưa có tài nguyên hình ảnh công khai để hiển thị.</p></div><?php endif; ?>

<?php elseif (is_array($context) && is_array($archive)): ?>
  <header class="archive-intro media-library-header"><p class="eyebrow">Thư viện hình ảnh</p><h1>Hiện vật qua hình ảnh</h1><p class="archive-summary">Ảnh công khai được lấy từ kho hình ảnh đã đủ điều kiện. Mỗi ảnh hỗ trợ đối chiếu hình dáng, chi tiết và dấu nhận diện mà không tạo thêm một địa chỉ nội dung giả.</p></header>
  <?php if (!empty($archive['items'])): ?>
    <div class="media-masonry library-grid">
      <?php foreach ($archive['items'] as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?>
        <figure class="library-item"><div class="library-image"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"<?php if (!empty($item['width'])): ?> width="<?php echo esc_attr((string) $item['width']); ?>"<?php endif; ?><?php if (!empty($item['height'])): ?> height="<?php echo esc_attr((string) $item['height']); ?>"<?php endif; ?>></div><figcaption><span class="eyebrow">Hình ảnh</span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? 'Hình ảnh hiện vật'))); ?></strong></figcaption></figure>
      <?php endforeach; ?>
    </div>
  <?php else: ?><div class="empty media-empty"><h2>Chưa có hình ảnh công khai</h2><p>Thư viện sẽ hiện ảnh thật ngay khi tài nguyên đủ điều kiện. Các bố cục khác vẫn dùng ảnh minh họa mặc định khi chưa có ảnh đại diện.</p></div><?php endif; ?>
  <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?><nav class="entity-pagination" aria-label="Phân trang thư viện"><?php for ($page = 1; $page <= $pages; $page++): $url = home_url('/thu-vien/' . ($page > 1 ? 'page/' . $page . '/' : '')); $current = $page === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $page); ?></a><?php endfor; ?></nav><?php endif; ?>
<?php else: ?><div class="empty"><h1>Thư viện chưa sẵn sàng</h1><p>Không thể tải dữ liệu hình ảnh.</p></div><?php endif; ?>
</main>
<?php get_footer(); ?>
