<?php
$context = $GLOBALS['nhk_core_media_context'] ?? null;
$archive = is_array($context) ? ($context['archive'] ?? []) : [];
$media = is_array($context) ? ($context['media'] ?? []) : [];
get_header();
?><main id="main-content" class="site-main media-video-shell">
<?php if (is_array($context) && ($context['mode'] ?? '') === 'detail' && $media !== []): ?>
  <p class="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">NHK</a> <span>/</span> <a href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Thư viện</a></p>
  <header class="archive-intro media-header"><p class="eyebrow">Thư viện hình ảnh</p><h1><?php echo esc_html((string) ($media['name'] ?? '')); ?></h1><p class="archive-summary">Thông tin hình ảnh và tài nguyên được quản lý theo mã hồ sơ.</p></header>
  <div class="media-detail-layout">
    <section><h2>Tài nguyên</h2><?php if (!empty($media['assets'])): ?><div class="media-asset-grid"><?php foreach ($media['assets'] as $asset): ?><article class="media-asset"><span class="media-asset-kind"><?php echo esc_html((string) ($asset['kind'] ?? 'asset')); ?></span><strong><?php echo esc_html((string) ($asset['mime_type'] ?? '')); ?></strong><p><?php echo esc_html((string) ($asset['width'] ?? '—')); ?> × <?php echo esc_html((string) ($asset['height'] ?? '—')); ?> · <?php echo esc_html((string) ($asset['byte_size'] ?? 0)); ?> bytes</p></article><?php endforeach; ?></div><?php else: ?><div class="empty"><p>Chưa có binary asset sẵn sàng để hiển thị.</p></div><?php endif; ?></section>

  </div>
<?php elseif (is_array($context) && is_array($archive)): ?>
  <header class="archive-intro"><p class="eyebrow">Thư viện</p><h1>Hình ảnh & media</h1><p class="archive-summary">Các hồ sơ hình ảnh đang hoạt động trong kho NHK.</p></header>
  <?php if (!empty($archive['items'])): ?><div class="media-card-grid"><?php foreach ($archive['items'] as $item): ?><article class="media-card"><p class="eyebrow">Media</p><h2><a href="<?php echo esc_url(home_url('/media/' . rawurlencode((string) $item['id']) . '/')); ?>"><?php echo esc_html((string) ($item['title'] ?? '')); ?></a></h2></article><?php endforeach; ?></div><?php else: ?><div class="empty media-empty"><h2>Chưa có media public</h2><p>Thư viện sẽ hiển thị sau khi asset và readiness được kiểm định.</p></div><?php endif; ?>
  <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?><nav class="entity-pagination" aria-label="Phân trang thư viện"><?php for ($page = 1; $page <= $pages; $page++): $url = home_url('/media/' . ($page > 1 ? 'page/' . $page . '/' : '')); $current = $page === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $page); ?></a><?php endfor; ?></nav><?php endif; ?>
<?php else: ?><div class="empty"><h1>Thư viện chưa sẵn sàng</h1><p>Không thể tải dữ liệu hình ảnh.</p></div><?php endif; ?></main><?php get_footer();
