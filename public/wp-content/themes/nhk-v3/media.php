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
  <section class="contextual-discovery media-downstream-discovery"><p class="eyebrow">Khám phá tiếp</p><h2>Đi tiếp từ hình ảnh này</h2><nav class="topic-cloud" aria-label="Khám phá tiếp từ hình ảnh"><?php foreach (nhk_v3_contextual_discovery_items('media') as $item): ?><a href="<?php echo esc_url(home_url($item['path'])); ?>"><?php echo esc_html($item['label']); ?> →</a><?php endforeach; ?></nav></section>

<?php elseif (is_array($context) && is_array($archive)): ?>
  <header class="archive-intro media-library-header"><p class="eyebrow">Thư viện hình ảnh</p><h1>Hiện vật qua hình ảnh</h1><p class="archive-summary">Ảnh công khai được lấy từ kho hình ảnh đã đủ điều kiện. Mỗi ảnh hỗ trợ đối chiếu hình dáng, chi tiết và dấu nhận diện mà không tạo thêm một địa chỉ nội dung giả.</p></header>
  <?php if (!empty($archive['items'])): ?>
    <div class="media-library-grid">
      <?php foreach ($archive['items'] as $item):
        $visual = nhk_v3_media_presentation($item, true);
        $fullImage = trim((string) ($item['image_url'] ?? ''));
        $image = $visual['url'];
        $hasRealImage = !empty($item['has_real_image']) && $image !== '';
        $title = nhk_v3_public_brand_text((string) ($item['title'] ?? 'Hình ảnh hiện vật'));
        $alt = (string) ($item['alt'] ?? $title);
        $summary = trim((string) ($item['summary'] ?? '')) ?: 'Ảnh tư liệu trong kho hình ảnh NHK.';
        $articleUrl = trim((string) ($item['article_url'] ?? ''));
      ?>
        <article class="library-item">
          <div class="library-image">
            <?php if ($hasRealImage): ?><a class="library-image-link" href="<?php echo esc_url($fullImage !== '' ? $fullImage : $image); ?>" data-full-src="<?php echo esc_url($fullImage !== '' ? $fullImage : $image); ?>" aria-label="Mở ảnh <?php echo esc_attr($title); ?>"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($alt); ?>" loading="lazy" decoding="async"<?php if ($visual['srcset'] !== ''): ?> srcset="<?php echo esc_attr($visual['srcset']); ?>"<?php endif; ?><?php if ($visual['sizes'] !== ''): ?> sizes="<?php echo esc_attr($visual['sizes']); ?>"<?php endif; ?><?php if ($visual['width'] > 0): ?> width="<?php echo esc_attr((string) $visual['width']); ?>"<?php endif; ?><?php if ($visual['height'] > 0): ?> height="<?php echo esc_attr((string) $visual['height']); ?>"<?php endif; ?>></a><?php else: ?><img src="<?php echo esc_url($fallback); ?>" alt="" loading="lazy" width="1200" height="750"><?php endif; ?>
          </div>
          <div class="library-item-body">
            <span class="eyebrow">Hình ảnh</span>
            <h2><?php if ($articleUrl !== ''): ?><a class="library-title-link" href="<?php echo esc_url($articleUrl); ?>"><?php echo esc_html($title); ?></a><?php else: ?><?php echo esc_html($title); ?><?php endif; ?></h2>
            <p class="library-summary"><?php echo esc_html($summary); ?></p>
            <?php if ($articleUrl !== ''): ?><a class="library-cta" href="<?php echo esc_url($articleUrl); ?>">Đọc bài viết <span aria-hidden="true">→</span></a><?php elseif ($hasRealImage): ?><span class="library-note">Ảnh chưa gắn bài viết</span><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?><div class="empty media-empty"><h2>Chưa có hình ảnh công khai</h2><p>Thư viện sẽ hiện ảnh thật ngay khi tài nguyên đủ điều kiện. Các bố cục khác vẫn dùng ảnh minh họa mặc định khi chưa có ảnh đại diện.</p></div><?php endif; ?>
  <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?><nav class="entity-pagination" aria-label="Phân trang thư viện"><?php for ($page = 1; $page <= $pages; $page++): $url = home_url('/thu-vien/' . ($page > 1 ? 'page/' . $page . '/' : '')); $current = $page === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $page); ?></a><?php endfor; ?></nav><?php endif; ?><section class="contextual-discovery media-downstream-discovery"><p class="eyebrow">Khám phá tiếp</p><h2>Đi tiếp từ thư viện hình ảnh</h2><nav class="topic-cloud" aria-label="Khám phá tiếp từ thư viện"><?php foreach (nhk_v3_contextual_discovery_items('media') as $item): ?><a href="<?php echo esc_url(home_url($item['path'])); ?>"><?php echo esc_html($item['label']); ?> →</a><?php endforeach; ?></nav></section>
<?php else: ?><div class="empty"><h1>Thư viện chưa sẵn sàng</h1><p>Không thể tải dữ liệu hình ảnh.</p></div><?php endif; ?>
</main>
<?php get_footer(); ?>
