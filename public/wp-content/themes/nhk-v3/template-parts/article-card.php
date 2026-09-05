<article class="card article-card">
  <a class="card-image" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
    <?php if (has_post_thumbnail()): ?>
      <?php the_post_thumbnail('medium_large', ['loading' => 'lazy', 'alt' => '']); ?>
    <?php else: ?>
      <img class="fallback-visual" src="<?php echo esc_url(get_theme_file_uri('/assets/default-archive.svg')); ?>" alt="" loading="lazy" width="1200" height="750">
    <?php endif; ?>
  </a>
  <div class="card-body">
    <p class="eyebrow"><?php $cats = get_the_category(); echo esc_html(nhk_v3_public_category_name((string) ($cats[0]->name ?? 'Bài viết'))); ?></p>
    <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
    <p class="card-excerpt"><?php echo esc_html(nhk_v3_excerpt()); ?></p>
    <div class="meta"><span><?php echo esc_html(get_the_author()); ?></span><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(nhk_v3_public_date()); ?></time></div>
  </div>
</article>
<?php /* Display fallback is presentation-only; native editorial permalink and semantic media/SEO remain separate. */ ?>
