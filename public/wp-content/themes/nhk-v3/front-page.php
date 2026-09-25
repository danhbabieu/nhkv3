<?php
$home = (new NHK_V3_Home_Page_Query())->read();
$GLOBALS['nhk_v3_home_data'] = $home;
$semantic = is_array($home['semantic'] ?? null) ? $home['semantic'] : [];
$heroMedia = is_array($semantic['hero_media'] ?? null) ? array_slice($semantic['hero_media'], 0, 1) : [];
$latestFeed = is_array($home['latest_feed'] ?? null) ? $home['latest_feed'] : [];
$primaryNavigation = (array) (nhk_v3_navigation_groups()['primary'] ?? []);
$heroEntryPoints = array_values(array_filter($primaryNavigation, static fn (mixed $item): bool => is_array($item) && in_array((string) ($item['label'] ?? ''), ['Thương hiệu', 'Nhóm đồng hồ'], true)));
$fallback = get_theme_file_uri('/assets/default-archive.svg');
get_header();
?>
<main id="main-content" class="site-main home-page-v2">
  <section class="hero home-hero-v2">
    <div class="hero-copy-block">
      <p class="eyebrow">Kho tri thức · hiện vật thật · nghiên cứu thật</p>
      <h1>Kho tri thức đồng hồ cổ<br> <em>dành cho người chơi và sưu tầm.</em></h1>
      <p class="hero-copy">Khám phá thương hiệu, nhóm đồng hồ và những câu chuyện trong kho NHK.</p>
      <?php get_search_form(); ?>
      <?php if ($heroEntryPoints !== []): ?><nav class="hero-entry-points" aria-label="Khám phá chính"><?php foreach ($heroEntryPoints as $item): ?><a href="<?php echo esc_url(home_url((string) $item['path'])); ?>"><?php echo esc_html((string) $item['label']); ?><span aria-hidden="true">→</span></a><?php endforeach; ?></nav><?php endif; ?>
    </div>
    <div class="hero-media-column">
    <div class="hero-visual" aria-label="Ảnh nổi bật từ kho NHK">
      <?php if ($heroMedia !== []): $item = $heroMedia[0]; $dimensions = nhk_v3_media_dimensions($item); $orientation = nhk_v3_media_orientation_class($dimensions['width'], $dimensions['height']); $alt = trim((string) ($item['alt'] ?? $item['title'] ?? 'Ảnh tư liệu NHK')); ?>
        <figure class="hero-image <?php echo esc_attr($orientation); ?>"><img src="<?php echo esc_url((string) $item['image_url']); ?>" alt="<?php echo esc_attr($alt); ?>" width="<?php echo esc_attr((string) max(1, $dimensions['width'])); ?>" height="<?php echo esc_attr((string) max(1, $dimensions['height'])); ?>"<?php if (trim((string) ($item['srcset'] ?? '')) !== ''): ?> srcset="<?php echo esc_attr((string) $item['srcset']); ?>"<?php endif; ?><?php if (trim((string) ($item['sizes'] ?? '')) !== ''): ?> sizes="<?php echo esc_attr((string) ($item['sizes'])); ?>"<?php endif; ?> loading="eager" fetchpriority="high"><figcaption><?php echo esc_html((string) ($item['title'] ?? 'Ảnh tư liệu NHK')); ?></figcaption></figure>
      <?php else: ?><div class="hero-empty" role="status">Kho ảnh đang được bổ sung.</div><?php endif; ?>
    </div>
    </div>
  </section>

  <?php if ($latestFeed !== []): ?>
  <section class="home-latest-feed" aria-labelledby="home-latest-title">
    <div class="section-head"><div><p class="eyebrow">Mới cập nhật</p><h2 id="home-latest-title">Mới nhất trong kho</h2><p class="section-deck">Những cập nhật mới nhất từ toàn bộ kho dữ liệu.</p></div></div>
    <div class="latest-feed-list">
      <?php foreach (array_slice($latestFeed, 0, 4) as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $attachmentId = (int) ($item['attachment_id'] ?? 0); $imageUrl = trim((string) ($item['image_url'] ?? '')); $imageSrcset = trim((string) ($item['image_srcset'] ?? $item['srcset'] ?? '')); $imageSizes = trim((string) ($item['image_sizes'] ?? $item['sizes'] ?? '')); $imageWidth = max(1, (int) ($item['width'] ?? 0)); $imageHeight = max(1, (int) ($item['height'] ?? 0)); ?>
        <article class="latest-feed-card latest-feed-row">
          <a class="latest-feed-card-link" href="<?php echo esc_url($url); ?>">
            <span class="latest-feed-card-thumb" aria-hidden="true">
              <?php if ($attachmentId > 0 && function_exists('wp_get_attachment_image')): ?>
                <?php echo wp_get_attachment_image((int) $item['attachment_id'], 'medium_large', false, ['class' => 'latest-feed-card-image', 'loading' => 'lazy', 'sizes' => $imageSizes !== '' ? $imageSizes : '(max-width: 767px) 120px, 220px']); ?>
              <?php elseif ($imageUrl !== ''): ?>
                <img class="latest-feed-card-image" src="<?php echo esc_url($imageUrl); ?>" alt="" width="<?php echo esc_attr((string) $imageWidth); ?>" height="<?php echo esc_attr((string) $imageHeight); ?>"<?php if ($imageSrcset !== ''): ?> srcset="<?php echo esc_attr($imageSrcset); ?>"<?php endif; ?><?php if ($imageSizes !== ''): ?> sizes="<?php echo esc_attr($imageSizes); ?>"<?php endif; ?> loading="lazy" decoding="async">
              <?php else: ?><img class="latest-feed-card-image fallback-visual" src="<?php echo esc_url($fallback); ?>" alt="" width="640" height="400" loading="lazy" decoding="async"><?php endif; ?>
            </span>
            <span class="latest-feed-row-body"><span class="latest-feed-row-meta"><span class="latest-feed-badge"><?php echo esc_html((string) ($item['label'] ?? 'Nội dung')); ?></span></span><span class="latest-feed-card-title"><?php echo esc_html((string) ($item['title'] ?? '')); ?></span><?php if (trim((string) ($item['summary'] ?? '')) !== ''): ?><span class="latest-feed-card-summary"><?php echo esc_html((string) ($item['summary'] ?? '')); ?></span><?php endif; ?></span>
          </a>
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
          <?php if (has_post_thumbnail()): the_post_thumbnail('medium_large', ['loading' => 'lazy', 'alt' => get_the_title()]); else: ?><img class="fallback-visual" src="<?php echo esc_url($fallback); ?>" alt="" width="1200" height="750" loading="lazy"><?php endif; ?>
        </a>
        <div class="featured-body"><p class="eyebrow"><?php $cats = get_the_category(); echo esc_html(nhk_v3_public_category_name((string) ($cats[0]->name ?? 'Bài viết'))); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><p><?php echo esc_html(nhk_v3_excerpt()); ?></p><div class="meta"><span><?php echo esc_html(get_the_author()); ?></span><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(nhk_v3_public_date()); ?></time></div></div>
      </article>
      <div class="featured-support">
        <?php foreach (array_slice($featured, 1) as $post): setup_postdata($post); ?>
          <article class="support-card"><a class="support-card-link" href="<?php the_permalink(); ?>"><?php if (has_post_thumbnail()): ?><span class="support-card-image"><?php the_post_thumbnail('thumbnail', ['loading' => 'lazy', 'alt' => get_the_title()]); ?></span><?php endif; ?><span class="support-card-body"><span class="eyebrow"><?php $cats = get_the_category(); echo esc_html(nhk_v3_public_category_name((string) ($cats[0]->name ?? 'Bài viết'))); ?></span><span class="support-card-title"><?php the_title(); ?></span><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(nhk_v3_public_date()); ?></time></span></a></article>
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

  <?php $renderableHomeSections = []; foreach (($home['sections'] ?? []) as $section) { $sectionUrl = nhk_v3_public_url($section['url'] ?? null); if ($sectionUrl === '' || empty($section['posts']) || !is_array($section['posts'])) continue; $renderableHomeSections[] = [$section, $sectionUrl]; } if ($renderableHomeSections !== []): ?>
  <div class="home-sections">
    <section class="home-feed">
      <?php foreach ($renderableHomeSections as [$section, $sectionUrl]): ?>
        <section class="home-section"><div class="section-head"><div><p class="eyebrow"><?php echo esc_html($section['label']); ?></p><h2><?php echo esc_html($section['label']); ?> mới</h2></div><a class="text-link" href="<?php echo esc_url($sectionUrl); ?>">Xem thêm →</a></div><div class="post-grid compact"><?php foreach ($section['posts'] as $post): setup_postdata($post); get_template_part('template-parts/article-card'); endforeach; wp_reset_postdata(); ?></div></section>
      <?php endforeach; ?>
    </section>
  </div>
  <?php endif; ?>

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
