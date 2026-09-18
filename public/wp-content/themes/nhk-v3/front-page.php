<?php
$home = (new NHK_V3_Home_Page_Query())->read();
$GLOBALS['nhk_v3_home_data'] = $home;
$semantic = is_array($home['semantic'] ?? null) ? $home['semantic'] : [];
$heroMedia = is_array($semantic['hero_media'] ?? null) ? array_slice($semantic['hero_media'], 0, 5) : [];
$fallback = get_theme_file_uri('/assets/default-archive.svg');
get_header();
?>
<main id="main-content" class="site-main home-page-v2">
  <section class="hero home-hero-v2 home-hero-with-slider">
    <div class="hero-copy-block">
      <p class="eyebrow">Kho tri thức · hiện vật thật · nghiên cứu thật</p>
      <h1>Kho tri thức đồng hồ cổ<br> <em>dành cho người chơi và sưu tầm.</em></h1>
      <p class="hero-tagline">Mỗi chiếc đồng hồ cổ mang một câu chuyện.</p>
      <p class="hero-copy">Khám phá thương hiệu, mẫu máy, bộ máy, bản nhạc, hình ảnh, video và những chi tiết giúp nhận diện từng hiện vật trong cùng một hệ thống tra cứu.</p>
      <ul class="hero-values"><li>Tra cứu thương hiệu và mẫu đồng hồ</li><li>Xem ảnh thực tế và video</li><li>Tìm hiểu bộ máy, bản nhạc, linh kiện</li><li>Kết nối tri thức với hiện vật sưu tầm</li></ul>
      <?php get_search_form(); ?>
    </div>
    <div class="hero-media-column">
    <div class="hero-visual-slider" data-nhk-hero-slider aria-label="Ảnh nổi bật từ kho NHK">
      <?php if ($heroMedia !== []): ?><div class="hero-slides">
        <?php foreach ($heroMedia as $index => $item): $dimensions = nhk_v3_media_dimensions($item); $orientation = nhk_v3_media_orientation_class($dimensions['width'], $dimensions['height']); $alt = trim((string) ($item['alt'] ?? $item['title'] ?? 'Ảnh tư liệu NHK')); ?>
          <figure class="hero-slide <?php echo esc_attr($orientation); ?><?php echo $index === 0 ? ' is-active' : ''; ?>" data-hero-slide="<?php echo esc_attr((string) $index); ?>"<?php echo $index === 0 ? '' : ' hidden'; ?>><img src="<?php echo esc_url((string) $item['image_url']); ?>" alt="<?php echo esc_attr($alt); ?>" width="<?php echo esc_attr((string) max(1, $dimensions['width'])); ?>" height="<?php echo esc_attr((string) max(1, $dimensions['height'])); ?>" <?php echo $index === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"'; ?>><figcaption><?php echo esc_html((string) ($item['title'] ?? 'Ảnh tư liệu NHK')); ?></figcaption></figure>
        <?php endforeach; ?>
      </div><div class="hero-slider-controls"><button type="button" data-hero-prev aria-label="Ảnh trước">←</button><span data-hero-status aria-live="polite">1 / <?php echo esc_html((string) count($heroMedia)); ?></span><button type="button" data-hero-next aria-label="Ảnh tiếp theo">→</button></div><div class="hero-slider-dots" role="tablist" aria-label="Chọn ảnh nổi bật"><?php foreach ($heroMedia as $index => $_item): ?><button type="button" role="tab" data-hero-dot="<?php echo esc_attr((string) $index); ?>" aria-label="Ảnh <?php echo esc_attr((string) ($index + 1)); ?>" aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"></button><?php endforeach; ?></div>
      <?php else: ?><div class="hero-slider-empty" role="status">Kho ảnh đang được bổ sung.</div><?php endif; ?>
    </div>
    <aside class="hero-index" aria-label="Lối vào nhanh">
      <?php /* Canonical discovery paths remain /thuong-hieu/, /loai-dong-ho/, /mau/, /bo-may/, /ban-nhac/, /so-sanh/, /linh-kien/, /hien-vat/ and /video/ (including home_url('/so-sanh/')); labels and rendering come from the shared navigation definition. */ ?>
      <?php $quickLinks = nhk_v3_navigation_items(); $quickIndex = 0; foreach ($quickLinks as $quickLabel => $quickPath): $quickIndex++; ?>
      <a href="<?php echo esc_url(home_url($quickPath)); ?>"><span><?php echo esc_html(str_pad((string) $quickIndex, 2, '0', STR_PAD_LEFT)); ?></span><strong><?php echo esc_html($quickLabel); ?></strong></a>
      <?php endforeach; ?>
    </aside>
    </div>
  </section>

  <?php $latestFeed = is_array($home['latest_feed'] ?? null) ? $home['latest_feed'] : []; if ($latestFeed !== []): ?>
  <section class="home-latest-feed" aria-labelledby="home-latest-title">
    <div class="section-head"><div><p class="eyebrow">Dòng hoạt động</p><h2 id="home-latest-title">Mới nhất trong kho</h2><p class="section-deck">Bài viết, tri thức, video, hình ảnh và hồ sơ công khai được sắp theo thời điểm mới nhất.</p></div><a class="text-link" href="<?php echo esc_url(home_url('/')); ?>#home-latest-title">Xem toàn bộ dòng mới →</a></div>
    <div class="latest-feed-grid">
      <?php foreach ($latestFeed as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $image = trim((string) ($item['image_url'] ?? '')); $width = max(1, (int) ($item['width'] ?? 0)); $height = max(1, (int) ($item['height'] ?? 0)); $displayTimestamp = trim((string) ($item['timestamp'] ?? '')) ?: trim((string) ($item['created_at'] ?? '')); $orientation = trim((string) ($item['orientation'] ?? 'unknown')) ?: 'unknown'; ?>
        <article class="latest-feed-card latest-feed-card--<?php echo esc_attr($orientation); ?><?php echo $image === '' ? ' has-no-image' : ''; ?>">
          <a class="latest-feed-visual" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr((string) ($item['title'] ?? '')); ?>">
            <?php if ($image !== ''): ?><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['title'] ?? '')); ?>" width="<?php echo esc_attr((string) $width); ?>" height="<?php echo esc_attr((string) $height); ?>" loading="lazy"><?php else: ?><span class="latest-feed-placeholder" aria-hidden="true"><?php echo esc_html(mb_strtoupper(mb_substr((string) ($item['label'] ?? 'NHK'), 0, 1))); ?></span><?php endif; ?>
          </a>
          <div class="latest-feed-body"><p class="eyebrow"><?php echo esc_html((string) ($item['label'] ?? 'Nội dung')); ?></p><h3><a href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) ($item['title'] ?? '')); ?></a></h3><?php if (trim((string) ($item['summary'] ?? '')) !== ''): ?><p><?php echo esc_html((string) $item['summary']); ?></p><?php endif; ?><time datetime="<?php echo esc_attr($displayTimestamp); ?>"><?php echo esc_html(nhk_v3_public_date(strtotime($displayTimestamp) ?: null)); ?></time></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($home['featured'])): $featured = $home['featured']; global $post; $post = $featured[0]; setup_postdata($post); ?>
  <section class="featured-section" aria-labelledby="featured-title">
    <div class="section-head"><div><p class="eyebrow">Đáng đọc</p><h2 id="featured-title">Tuyển chọn từ ban biên tập</h2><p class="section-deck">Những bài viết được chọn để đọc sâu hơn sau khi bạn đã lướt qua dòng mới.</p></div></div>
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

  <?php $hubs = is_array($semantic['hubs'] ?? null) ? $semantic['hubs'] : []; if ($hubs !== []): ?>
  <section class="home-semantic-section home-hubs">
    <div class="section-head"><div><p class="eyebrow">Duyệt theo cấu trúc</p><h2>Đi sâu vào kho dữ liệu</h2></div></div>
    <div class="home-hub-grid">
      <?php foreach ($hubs as $hub): $url = nhk_v3_public_url($hub['url'] ?? null); if ($url === '') continue; $hubLabel = trim((string) ($hub['label'] ?? '')) ?: nhk_v3_public_type((string) ($hub['type'] ?? '')); ?>
      <a class="hub-card" href="<?php echo esc_url($url); ?>">
        <span><?php echo esc_html($hubLabel); ?></span>
        <strong><?php echo esc_html((string) ($hub['total'] ?? 0)); ?> hồ sơ</strong>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php $clockGroups = is_array($semantic['clock_groups'] ?? null) ? $semantic['clock_groups'] : []; if ($clockGroups !== []): ?>
  <section class="home-semantic-section clock-groups-home">
    <div class="section-head"><div><p class="eyebrow">Nhóm đồng hồ</p><h2>Khám phá theo nhóm đồng hồ</h2></div><?php if ((int) ($semantic['clock_groups_total'] ?? count($clockGroups)) > count($clockGroups)): ?><a class="text-link" href="<?php echo esc_url(home_url('/loai-dong-ho/')); ?>">Khám phá tất cả nhóm đồng hồ →</a><?php endif; ?></div>
    <div class="visual-card-grid clock-group-grid">
      <?php foreach ($clockGroups as $item): get_template_part('template-parts/presentation/entity-card', null, ['item' => ['title' => $item['title'] ?? '', 'url' => $item['url'] ?? null, 'image_url' => $item['image_url'] ?? null, 'image_alt' => $item['image_alt'] ?? '', 'description' => $item['description'] ?? '', 'profile_label' => 'Nhóm đồng hồ']]); endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="content-layout home-layout">
    <section class="home-feed">
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
      <a class="visual-card" href="<?php echo esc_url($url); ?>"><span class="visual-frame"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['image_alt'] ?? '')); ?>" loading="lazy"></span><span class="visual-card-body"><small><?php echo esc_html(nhk_v3_public_type((string) $item['type'])); ?></small><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></span></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php $mediaItems = is_array($semantic['media'] ?? null) ? $semantic['media'] : []; if ($mediaItems !== []): ?>
  <section class="home-semantic-section visual-archive-section">
    <div class="section-head"><div><p class="eyebrow">Kho hình ảnh</p><h2>Hình ảnh từ dữ liệu đã lưu</h2></div><?php if ((int) ($semantic['media_total'] ?? count($mediaItems)) > count($mediaItems)): ?><a class="text-link" href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Mở thư viện →</a><?php endif; ?></div>
    <div class="media-mosaic"><?php foreach ($mediaItems as $item): get_template_part('template-parts/presentation/media-card', null, ['item' => $item]); endforeach; ?></div>
  </section>
  <?php endif; ?>

  <?php $videos = is_array($semantic['videos'] ?? null) ? $semantic['videos'] : []; if ($videos !== []): ?>
  <section class="home-semantic-section">
    <div class="section-head"><div><p class="eyebrow">Video</p><h2>Xem và nghe hiện vật</h2></div><?php if ((int) ($semantic['videos_total'] ?? count($videos)) > count($videos)): ?><a class="text-link" href="<?php echo esc_url(home_url('/video/')); ?>">Xem tất cả →</a><?php endif; ?></div>
    <div class="video-card-grid"><?php foreach ($videos as $item): get_template_part('template-parts/presentation/video-card', null, ['item' => $item]); endforeach; ?></div>
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
