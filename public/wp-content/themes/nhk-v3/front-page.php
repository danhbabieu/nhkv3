<?php
$home = (new NHK_V3_Home_Page_Query())->read();
$GLOBALS['nhk_v3_home_data'] = $home;
$semantic = is_array($home['semantic'] ?? null) ? $home['semantic'] : [];
$fallback = get_theme_file_uri('/assets/default-archive.svg');
get_header();
?>
<main id="main-content" class="site-main home-page-v2">
  <section class="hero home-hero-v2">
    <div class="hero-copy-block">
      <p class="eyebrow">Kho tri thức · hình ảnh · video · hiện vật</p>
      <h1>Khám phá đồng hồ cổ<br><em>từ hiện vật đến tri thức.</em></h1>
      <p class="hero-copy">Một cửa vào chung cho bài nghiên cứu, thương hiệu, mẫu, biến thể, bộ máy, bản nhạc, hình ảnh, video và từ điển đang được lưu trữ trong hệ thống.</p>
      <?php get_search_form(); ?>
    </div>
    <aside class="hero-index" aria-label="Lối vào nhanh">
      <a href="<?php echo esc_url(home_url('/thuong-hieu/')); ?>"><span>01</span><strong>Thương hiệu</strong></a>
      <a href="<?php echo esc_url(home_url('/mau/')); ?>"><span>02</span><strong>Mẫu & biến thể</strong></a>
      <a href="<?php echo esc_url(home_url('/bo-may/')); ?>"><span>03</span><strong>Bộ máy</strong></a>
      <a href="<?php echo esc_url(home_url('/thu-vien/')); ?>"><span>04</span><strong>Hình ảnh</strong></a>
      <a href="<?php echo esc_url(home_url('/video/')); ?>"><span>05</span><strong>Video</strong></a>
      <a href="<?php echo esc_url(home_url('/tu-dien/')); ?>"><span>06</span><strong>Từ điển</strong></a>
    </aside>
  </section>

  <?php $hubs = is_array($semantic['hubs'] ?? null) ? $semantic['hubs'] : []; if ($hubs !== []): ?>
  <section class="home-semantic-section home-hubs">
    <div class="section-head"><div><p class="eyebrow">Duyệt theo cấu trúc</p><h2>Đi sâu vào kho dữ liệu</h2></div></div>
    <div class="home-hub-grid">
      <?php foreach ($hubs as $hub): $url = nhk_v3_public_url($hub['url'] ?? null); if ($url === '') continue; ?>
      <a class="hub-card" href="<?php echo esc_url($url); ?>">
        <span><?php echo esc_html(nhk_v3_public_type((string) ($hub['type'] ?? ''))); ?></span>
        <strong><?php echo esc_html((string) ($hub['total'] ?? 0)); ?> hồ sơ</strong>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($home['featured'])): $featured = $home['featured']; global $post; $post = $featured[0]; setup_postdata($post); ?>
  <section class="featured-section">
    <div class="section-head"><div><p class="eyebrow">Tuyển chọn</p><h2>Câu chuyện đáng đọc</h2></div></div>
    <div class="featured-layout">
      <article class="featured-lead">
        <a class="featured-image" href="<?php the_permalink(); ?>">
          <?php if (has_post_thumbnail()): the_post_thumbnail('large', ['loading' => 'eager', 'fetchpriority' => 'high', 'alt' => get_the_title()]); else: ?><img class="fallback-visual" src="<?php echo esc_url($fallback); ?>" alt="" width="1200" height="750"><?php endif; ?>
        </a>
        <div class="featured-body"><p class="eyebrow"><?php $cats = get_the_category(); echo esc_html(nhk_v3_public_category_name((string) ($cats[0]->name ?? 'Bài viết'))); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><p><?php echo esc_html(nhk_v3_excerpt()); ?></p><div class="meta"><span><?php echo esc_html(get_the_author()); ?></span><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(nhk_v3_public_date()); ?></time></div></div>
      </article>
      <div class="featured-support">
        <?php foreach (array_slice($featured, 1) as $post): setup_postdata($post); ?>
          <article class="support-card"><p class="eyebrow"><?php $cats = get_the_category(); echo esc_html(nhk_v3_public_category_name((string) ($cats[0]->name ?? 'Bài viết'))); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><p><?php echo esc_html(nhk_v3_excerpt()); ?></p><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(nhk_v3_public_date()); ?></time></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php wp_reset_postdata(); endif; ?>

  <div class="content-layout home-layout">
    <section class="home-feed">
      <div class="section-head"><div><p class="eyebrow">Mới nhất</p><h2>Những câu chuyện mới</h2></div></div>
      <?php if (!empty($home['latest'])): ?><div class="post-grid"><?php foreach ($home['latest'] as $post): setup_postdata($post); get_template_part('template-parts/article-card'); endforeach; wp_reset_postdata(); ?></div><?php else: ?><div class="empty"><p>Chưa có bài viết công khai.</p></div><?php endif; ?>
      <?php foreach (($home['sections'] ?? []) as $section): $sectionUrl = nhk_v3_public_url($section['url'] ?? null); if ($sectionUrl === '' || empty($section['posts'])) continue; ?>
        <section class="home-section"><div class="section-head"><div><p class="eyebrow"><?php echo esc_html($section['label']); ?></p><h2><?php echo esc_html($section['label']); ?> mới</h2></div><a class="text-link" href="<?php echo esc_url($sectionUrl); ?>">Xem thêm →</a></div><div class="post-grid compact"><?php foreach ($section['posts'] as $post): setup_postdata($post); get_template_part('template-parts/article-card'); endforeach; wp_reset_postdata(); ?></div></section>
      <?php endforeach; ?>
    </section>
    <?php get_sidebar(); ?>
  </div>

  <?php $entities = is_array($semantic['entities'] ?? null) ? $semantic['entities'] : []; if ($entities !== []): ?>
  <section class="home-semantic-section">
    <div class="section-head"><div><p class="eyebrow">Hồ sơ nổi bật</p><h2>Đi từ hiện vật sang cấu trúc</h2></div></div>
    <div class="visual-card-grid">
      <?php foreach ($entities as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?>
      <a class="visual-card" href="<?php echo esc_url($url); ?>"><span class="visual-frame"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['image_alt'] ?? '')); ?>" loading="lazy"></span><span class="visual-card-body"><small><?php echo esc_html(nhk_v3_public_type((string) ($item['type'] ?? ''))); ?></small><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></span></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php $mediaItems = is_array($semantic['media'] ?? null) ? $semantic['media'] : []; if ($mediaItems !== []): ?>
  <section class="home-semantic-section visual-archive-section">
    <div class="section-head"><div><p class="eyebrow">Kho hình ảnh</p><h2>Hình ảnh từ dữ liệu đã lưu</h2></div><a class="text-link" href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Mở thư viện →</a></div>
    <div class="media-mosaic"><?php foreach ($mediaItems as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"><figcaption><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? 'Hình ảnh'))); ?></figcaption></figure><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <?php $videos = is_array($semantic['videos'] ?? null) ? $semantic['videos'] : []; if ($videos !== []): ?>
  <section class="home-semantic-section">
    <div class="section-head"><div><p class="eyebrow">Video</p><h2>Xem và nghe hiện vật</h2></div><a class="text-link" href="<?php echo esc_url(home_url('/video/')); ?>">Xem tất cả →</a></div>
    <div class="video-card-grid"><?php foreach ($videos as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $thumb = trim((string) ($item['thumbnail_url'] ?? '')) ?: $fallback; ?><a class="video-card" href="<?php echo esc_url($url); ?>"><span class="visual-frame video-thumb"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"><span class="play-mark" aria-hidden="true">▶</span></span><span class="visual-card-body"><small>Video</small><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></span></a><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <?php $knowledge = is_array($semantic['knowledge'] ?? null) ? $semantic['knowledge'] : []; if ($knowledge !== []): ?>
  <section class="home-semantic-section knowledge-home">
    <div class="section-head"><div><p class="eyebrow">Tri thức đã lưu</p><h2>Những điều có thể tra cứu tiếp</h2></div><a class="text-link" href="<?php echo esc_url(home_url('/tri-thuc/')); ?>">Mở kho tri thức →</a></div>
    <div class="knowledge-strip"><?php foreach ($knowledge as $item): ?><article><span><?php echo esc_html(nhk_v3_public_label((string) ($item['type'] ?? 'fact'))); ?></span><p><?php echo esc_html((string) ($item['text'] ?? '')); ?></p></article><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <?php $dictionary = is_array($semantic['dictionary'] ?? null) ? $semantic['dictionary'] : []; if ($dictionary !== []): ?>
  <section class="home-semantic-section dictionary-home">
    <div class="section-head"><div><p class="eyebrow">Từ điển</p><h2>Cách gọi trong giới sưu tầm</h2></div><a class="text-link" href="<?php echo esc_url(home_url('/tu-dien/')); ?>">Mở từ điển →</a></div>
    <div class="dictionary-grid"><?php foreach ($dictionary as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong><?php if (($item['description'] ?? '') !== ''): ?><span><?php echo esc_html(wp_trim_words((string) $item['description'], 18)); ?></span><?php endif; ?></a><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <?php if (!empty($home['topics'])): ?>
  <section class="home-semantic-section topics-section"><div class="section-head"><div><p class="eyebrow">Được quan tâm</p><h2>Chủ đề trong kho</h2></div></div><div class="topic-cloud"><?php foreach ($home['topics'] as $topic): $topicUrl = nhk_v3_public_url(get_category_link($topic)); if ($topicUrl === '') continue; ?><a href="<?php echo esc_url($topicUrl); ?>"><?php echo esc_html(nhk_v3_public_category_name((string) $topic->name)); ?><span><?php echo esc_html((string) $topic->count); ?></span></a><?php endforeach; ?></div></section>
  <?php endif; ?>
</main>
<?php get_footer(); ?>
