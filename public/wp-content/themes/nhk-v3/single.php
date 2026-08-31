<?php get_header(); ?>
<main id="main-content" class="site-main article-shell">
<?php while (have_posts()): the_post();
    $related = apply_filters('nhk_v3_post_related_content', ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []], get_the_ID());
    $related = is_array($related) ? $related : ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
?>
  <article class="article">
    <p class="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">NHK</a> <span>/</span> <?php echo nhk_v3_post_categories(', '); ?></p>
    <header class="article-header">
      <p class="eyebrow"><?php echo nhk_v3_post_categories(' · '); ?></p>
      <h1><?php the_title(); ?></h1>
      <p class="standfirst"><?php echo esc_html(nhk_v3_excerpt()); ?></p>
      <div class="article-meta"><?php echo esc_html(get_the_author()); ?> · <?php echo esc_html(get_the_date()); ?><?php if (get_the_modified_time('U') !== get_the_time('U')): ?> · Cập nhật <?php echo esc_html(get_the_modified_date()); ?><?php endif; ?></div>
    </header>
    <?php if (has_post_thumbnail()): ?><figure class="article-featured"><?php the_post_thumbnail('large', ['loading' => 'eager', 'fetchpriority' => 'high', 'alt' => get_the_title()]); ?><?php if (get_the_post_thumbnail_caption()): ?><figcaption><?php echo esc_html(get_the_post_thumbnail_caption()); ?></figcaption><?php endif; ?></figure><?php endif; ?>
    <div class="article-content"><?php the_content(); ?></div>
  </article>
  <?php if (array_filter($related)): ?>
    <section class="post-related" aria-label="Nội dung liên quan">
      <div class="section-head"><div><p class="eyebrow">Đọc tiếp</p><h2>Khám phá thêm</h2></div></div>
      <?php $relatedEntities = is_array($related['entities'] ?? null) ? array_filter($related['entities'], static fn($item): bool => is_array($item) && nhk_v3_public_url($item['url'] ?? null) !== '') : []; if ($relatedEntities !== []): ?><div class="post-related-group"><h3>Hồ sơ liên quan</h3><div class="related-grid"><?php foreach ($relatedEntities as $item): ?><a class="related-card" href="<?php echo esc_url(nhk_v3_public_url($item['url'])); ?>"><span class="related-type"><?php echo esc_html(nhk_v3_public_type((string) ($item['type'] ?? ''))); ?></span><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
      <?php $relatedArticles = is_array($related['articles'] ?? null) ? array_filter($related['articles'], static fn($item): bool => is_array($item) && nhk_v3_public_url($item['url'] ?? null) !== '') : []; if ($relatedArticles !== []): ?><div class="post-related-group"><h3>Bài viết liên quan</h3><div class="related-grid"><?php foreach ($relatedArticles as $item): ?><a class="related-card" href="<?php echo esc_url(nhk_v3_public_url($item['url'])); ?>"><span class="related-type">Bài viết</span><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
      <?php $relatedMedia = is_array($related['media'] ?? null) ? array_filter($related['media'], static fn($item): bool => is_array($item) && nhk_v3_public_url($item['url'] ?? null) !== '') : []; if ($relatedMedia !== []): ?><div class="post-related-group"><h3>Media liên quan</h3><div class="related-grid"><?php foreach ($relatedMedia as $item): ?><a class="related-card" href="<?php echo esc_url(nhk_v3_public_url($item['url'])); ?>"><span class="related-type">Thư viện</span><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
      <?php $relatedVideos = is_array($related['videos'] ?? null) ? array_filter($related['videos'], static fn($item): bool => is_array($item) && nhk_v3_public_url($item['url'] ?? null) !== '') : []; if ($relatedVideos !== []): ?><div class="post-related-group"><h3>Video liên quan</h3><div class="related-grid"><?php foreach ($relatedVideos as $item): ?><a class="related-card" href="<?php echo esc_url(nhk_v3_public_url($item['url'])); ?>" rel="noopener noreferrer"><span class="related-type">Nguồn video</span><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
    </section>
  <?php endif; ?>
<?php endwhile; ?>
</main>
<?php get_footer(); ?>
