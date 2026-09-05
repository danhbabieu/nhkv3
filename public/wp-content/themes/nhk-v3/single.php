<?php
get_header();
$fallback = get_theme_file_uri('/assets/default-archive.svg');
?>
<main id="main-content" class="site-main article-shell article-v2">
<?php while (have_posts()): the_post();
  $postId = get_the_ID();
  $articleSeo = nhk_v3_article_media_seo($postId);
  $featuredAlt = trim((string) ($articleSeo['alt'] ?? ''));
  $mediaProjection = apply_filters('nhk_v3_article_media_gallery', ['representative' => null, 'evidence' => [], 'gallery' => []], $postId);
  $mediaProjection = is_array($mediaProjection) ? $mediaProjection : ['representative' => null, 'evidence' => [], 'gallery' => []];
  $representative = is_array($mediaProjection['representative'] ?? null) ? $mediaProjection['representative'] : null;
  $gallery = is_array($mediaProjection['gallery'] ?? null) ? $mediaProjection['gallery'] : [];
  $related = apply_filters('nhk_v3_post_related_content', ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []], $postId);
  $related = is_array($related) ? $related : ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
  $direct = ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
  $derived = ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
  foreach ($direct as $group => $_) foreach ((array) ($related[$group] ?? []) as $item) if (is_array($item)) { $target = (($item['relationship_class'] ?? 'DIRECT') === 'DERIVED') ? 'derived' : 'direct'; ${$target}[$group][] = $item; }
  $dictionaryTerms = apply_filters('nhk_v3_public_dictionary_terms_for_text', [], get_the_title() . ' ' . wp_strip_all_tags((string) get_the_content(null, false, $postId)));
  $dictionaryTerms = is_array($dictionaryTerms) ? $dictionaryTerms : [];
?>
  <p class="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">NHK</a> <span>/</span> <?php echo nhk_v3_post_categories(', '); ?></p>
  <div class="article-context-layout">
    <article class="article">
      <header class="article-header">
        <p class="eyebrow"><?php echo nhk_v3_post_categories(' · '); ?></p>
        <h1><?php the_title(); ?></h1>
        <p class="standfirst"><?php echo esc_html(nhk_v3_excerpt()); ?></p>
        <div class="article-meta"><?php echo esc_html(get_the_author()); ?> · <?php echo esc_html(nhk_v3_public_date()); ?><?php if (get_the_modified_time('U') !== get_the_time('U')): ?> · Cập nhật <?php echo esc_html(nhk_v3_public_date((int) get_the_modified_time('U'))); ?><?php endif; ?></div>
      </header>

      <figure class="article-featured">
        <?php if (has_post_thumbnail()): ?>
          <?php the_post_thumbnail('large', ['loading' => 'eager', 'fetchpriority' => 'high', 'alt' => $featuredAlt !== '' ? $featuredAlt : get_the_title()]); ?>
          <?php if (get_the_post_thumbnail_caption()): ?><figcaption><?php echo esc_html(get_the_post_thumbnail_caption()); ?></figcaption><?php endif; ?>
        <?php elseif (trim((string) ($representative['url'] ?? '')) !== ''): ?>
          <img src="<?php echo esc_url((string) $representative['url']); ?>" alt="<?php echo esc_attr((string) (($representative['alt'] ?? '') ?: get_the_title())); ?>" loading="eager" fetchpriority="high">
        <?php else: ?>
          <img class="fallback-visual" src="<?php echo esc_url($fallback); ?>" alt="" width="1200" height="750">
        <?php endif; ?>
      </figure>

      <div class="article-content"><?php the_content(); ?></div>

      <?php $galleryImages = array_values(array_filter($gallery, static fn($item): bool => is_array($item) && trim((string) ($item['url'] ?? '')) !== '')); if ($galleryImages !== []): ?>
      <section class="article-media-gallery"><div class="section-head"><div><p class="eyebrow">Hình ảnh liên quan trực tiếp</p><h2>Ảnh trong hồ sơ bài viết</h2></div></div><div class="media-mosaic"><?php foreach ($galleryImages as $item): ?><figure class="media-figure"><img src="<?php echo esc_url((string) $item['url']); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? '')); ?>" loading="lazy"></figure><?php endforeach; ?></div></section>
      <?php endif; ?>
    </article>

    <aside class="context-rail article-rail" aria-label="Ngữ cảnh bài viết">
      <?php if ($dictionaryTerms !== []): ?><div class="context-box"><p class="eyebrow">Từ điển trong bài</p><ul class="context-list"><?php foreach ($dictionaryTerms as $term): $url = nhk_v3_public_url($term['url'] ?? null); if ($url === '') continue; ?><li><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html((string) ($term['title'] ?? '')); ?></strong><?php if (($term['description'] ?? '') !== ''): ?><span><?php echo esc_html(wp_trim_words((string) $term['description'], 13)); ?></span><?php endif; ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="context-box"><p class="eyebrow">Xem thêm</p><nav><a href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Hình ảnh</a><a href="<?php echo esc_url(home_url('/video/')); ?>">Video</a><a href="<?php echo esc_url(home_url('/tu-dien/')); ?>">Từ điển</a><a href="<?php echo esc_url(home_url('/thuong-hieu/')); ?>">Thương hiệu</a></nav></div>
    </aside>
  </div>

  <?php foreach ([['key' => 'direct', 'data' => $direct, 'eyebrow' => 'Quan hệ cấp 1', 'title' => 'Liên quan trực tiếp'], ['key' => 'derived', 'data' => $derived, 'eyebrow' => 'Quan hệ mở rộng', 'title' => 'Mở rộng từ quan hệ nền']] as $block): $groups = $block['data']; if (!array_filter($groups)) continue; ?>
  <section class="post-related relation-<?php echo esc_attr($block['key']); ?>" aria-label="<?php echo esc_attr($block['title']); ?>">
    <div class="section-head"><div><p class="eyebrow"><?php echo esc_html($block['eyebrow']); ?></p><h2><?php echo esc_html($block['title']); ?></h2></div></div>
    <?php if ($groups['entities'] !== []): ?><div class="post-related-group"><h3>Hồ sơ liên quan</h3><div class="related-grid"><?php foreach ($groups['entities'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type"><?php echo esc_html(nhk_v3_public_type((string) ($item['type'] ?? ''))); ?></span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
    <?php if ($groups['articles'] !== []): ?><div class="post-related-group"><h3>Bài viết liên quan</h3><div class="related-grid"><?php foreach ($groups['articles'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type">Bài viết</span><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
    <?php if ($groups['media'] !== []): ?><div class="post-related-group"><h3>Hình ảnh</h3><div class="media-mosaic related-media-grid"><?php foreach ($groups['media'] as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"><figcaption><?php echo esc_html((string) ($item['title'] ?? 'Hình ảnh')); ?></figcaption></figure><?php endforeach; ?></div></div><?php endif; ?>
    <?php if ($groups['videos'] !== []): ?><div class="post-related-group"><h3>Video</h3><div class="video-card-grid"><?php foreach ($groups['videos'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $thumb = trim((string) ($item['thumbnail_url'] ?? '')) ?: $fallback; ?><a class="video-card" href="<?php echo esc_url($url); ?>"><span class="visual-frame video-thumb"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"><span class="play-mark" aria-hidden="true">▶</span></span><span class="visual-card-body"><small>Video</small><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></span></a><?php endforeach; ?></div></div><?php endif; ?>
  </section>
  <?php endforeach; ?>
<?php endwhile; ?>
</main>
<?php get_footer(); ?>
