<?php
$context = $GLOBALS['nhk_core_video_context'] ?? null;
$archive = is_array($context) ? ($context['archive'] ?? []) : [];
$video = is_array($context) ? ($context['video'] ?? []) : [];
$fallback = get_theme_file_uri('/assets/default-archive.svg');
get_header();
?>
<main id="main-content" class="site-main media-video-shell video-library-v2">
<?php if (is_array($context) && ($context['mode'] ?? '') === 'detail' && $video !== []):
  $platform = strtolower((string) ($video['platform'] ?? ''));
  $externalId = (string) ($video['external_id'] ?? '');
  $title = (string) (($video['title'] ?? '') ?: 'Video');
  $related = is_array($video['related'] ?? null) ? $video['related'] : [];
  $direct = ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
  $derived = ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
  foreach ($direct as $group => $_) foreach ((array) ($related[$group] ?? []) as $item) if (is_array($item)) { $target = (($item['relationship_class'] ?? 'DIRECT') === 'DERIVED') ? 'derived' : 'direct'; ${$target}[$group][] = $item; }
?>
  <p class="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">NHK</a> <span>/</span> <a href="<?php echo esc_url(home_url('/video/')); ?>">Video</a></p>
  <header class="archive-intro media-header"><p class="eyebrow">Video hiện vật</p><h1><?php echo esc_html(nhk_v3_public_brand_text($title)); ?></h1><?php if (($video['summary'] ?? '') !== ''): ?><p class="archive-summary"><?php echo esc_html(nhk_v3_public_brand_text((string) $video['summary'])); ?></p><?php endif; ?></header>
  <?php if (($video['source_available'] ?? true) && $platform === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/', $externalId)): ?><div class="video-frame"><iframe src="<?php echo esc_url((string) ($video['embed_url'] ?? 'https://www.youtube-nocookie.com/embed/' . $externalId)); ?>" title="<?php echo esc_attr(nhk_v3_public_brand_text($title)); ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div><?php elseif (($video['source_available'] ?? true) === false): ?><div class="empty media-empty"><h2>Video nguồn hiện không khả dụng</h2><p>Hồ sơ vẫn được giữ lại để bảo toàn ngữ cảnh.</p></div><?php endif; ?>

  <div class="video-detail-layout">
    <div>
      <?php if (($video['body'] ?? '') !== '' || ($video['why_this_matters'] ?? '') !== ''): ?><article class="video-editorial"><h2>Về video này</h2><?php if (($video['body'] ?? '') !== ''): ?><p><?php echo esc_html(nhk_v3_public_brand_text((string) $video['body'])); ?></p><?php endif; ?><?php if (($video['why_this_matters'] ?? '') !== ''): ?><p><?php echo esc_html(nhk_v3_public_brand_text((string) $video['why_this_matters'])); ?></p><?php endif; ?></article><?php endif; ?>
    </div>
    <aside class="context-rail"><div class="context-box"><p class="eyebrow">Thông tin nguồn</p><dl class="compact-facts"><dt>Nền tảng</dt><dd><?php echo esc_html($platform); ?></dd><?php if (is_array($video['category'] ?? null)): ?><dt>Chủ đề</dt><dd><?php echo esc_html((string) ($video['category']['label'] ?? '')); ?></dd><?php endif; ?></dl><?php $sourceUrl = nhk_v3_public_url($video['url'] ?? null); if ($sourceUrl !== ''): ?><a class="text-link" href="<?php echo esc_url($sourceUrl); ?>" rel="noopener noreferrer">Mở nguồn video ↗</a><?php endif; ?></div></aside>
  </div>

  <?php foreach ([['key' => 'direct', 'data' => $direct, 'title' => 'Liên quan trực tiếp'], ['key' => 'derived', 'data' => $derived, 'title' => 'Mở rộng từ quan hệ nền']] as $block): if (!array_filter($block['data'])) continue; ?><section class="video-related relation-<?php echo esc_attr($block['key']); ?>"><div class="section-head"><div><p class="eyebrow">Ngữ cảnh</p><h2><?php echo esc_html($block['title']); ?></h2></div></div><?php foreach (['entities' => 'Hồ sơ', 'articles' => 'Bài viết'] as $group => $heading): if (empty($block['data'][$group])) continue; ?><div class="relation-group"><h3><?php echo esc_html($heading); ?></h3><div class="related-grid"><?php foreach ($block['data'][$group] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type"><?php echo esc_html(nhk_v3_public_type((string) ($item['type'] ?? $group))); ?></span><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></a><?php endforeach; ?></div></div><?php endforeach; ?><?php if (!empty($block['data']['media'])): ?><div class="media-mosaic related-media-grid"><?php foreach ($block['data']['media'] as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"><figcaption><?php echo esc_html((string) ($item['title'] ?? 'Hình ảnh')); ?></figcaption></figure><?php endforeach; ?></div><?php endif; ?></section><?php endforeach; ?>

<?php elseif (is_array($context) && is_array($archive)): ?>
  <header class="archive-intro"><p class="eyebrow">Kho video</p><h1>Xem và nghe hiện vật</h1><p class="archive-summary">Video được giữ theo nguồn tham chiếu bên ngoài và chỉ xuất hiện khi hồ sơ đủ điều kiện công khai.</p></header>
  <?php if (!empty($archive['items'])): ?><div class="video-card-grid archive-video-grid"><?php foreach ($archive['items'] as $item): $itemUrl = nhk_v3_public_url($item['public_url'] ?? null); if ($itemUrl === '') continue; $thumb = trim((string) ($item['source_thumbnail_url'] ?? '')) ?: $fallback; ?><a class="video-card" href="<?php echo esc_url($itemUrl); ?>"><span class="visual-frame video-thumb"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"><span class="play-mark" aria-hidden="true">▶</span></span><span class="visual-card-body"><small><?php echo esc_html((string) ($item['platform'] ?? 'Video')); ?></small><strong><?php echo esc_html(nhk_v3_public_brand_text((string) (($item['title'] ?? '') ?: 'Video'))); ?></strong><?php if (($item['summary'] ?? '') !== ''): ?><span><?php echo esc_html(wp_trim_words((string) $item['summary'], 20)); ?></span><?php endif; ?></span></a><?php endforeach; ?></div><?php else: ?><div class="empty media-empty"><h2>Chưa có video công khai</h2><p>Video sẽ xuất hiện sau khi nguồn hợp lệ và được kiểm duyệt.</p></div><?php endif; ?>
  <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?><nav class="entity-pagination" aria-label="Phân trang video"><?php for ($page = 1; $page <= $pages; $page++): $url = home_url('/video/' . ($page > 1 ? 'page/' . $page . '/' : '')); $current = $page === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $page); ?></a><?php endfor; ?></nav><?php endif; ?>
<?php else: ?><div class="empty"><h1>Video chưa sẵn sàng</h1><p>Không thể tải dữ liệu video.</p></div><?php endif; ?>
</main>
<?php get_footer(); ?>
