<?php
$item = is_array($args['item'] ?? null) ? $args['item'] : [];
$title = trim((string) ($item['title'] ?? $item['name'] ?? 'Video'));
$context = trim((string) ($item['subject_context'] ?? $item['context'] ?? ''));
$date = trim((string) ($item['published_at'] ?? ''));
$poster = trim((string) ($item['poster_url'] ?? $item['thumbnail_url'] ?? $item['thumbnail']['url'] ?? ''));
$visualKind = (string) ($item['visual_kind'] ?? ($poster !== '' ? 'image' : 'fallback'));
$url = nhk_v3_public_url($item['url'] ?? $item['detail_url'] ?? null);
$dimensions = nhk_v3_media_dimensions($item);
$orientationClass = nhk_v3_media_orientation_class($dimensions['width'], $dimensions['height']);
$context = nhk_v3_video_summary($context, $title);
if ($title === '') return;
?><article class="video-card visual-card <?php echo esc_attr($orientationClass); ?>"><?php if ($url !== ''): ?><a class="video-card-link" href="<?php echo esc_url($url); ?>" aria-label="Xem video: <?php echo esc_attr($title); ?>"><?php endif; ?><span class="video-poster"><?php if ($visualKind === 'image' && $poster !== ''): ?><img src="<?php echo esc_url($poster); ?>" alt="" loading="lazy" decoding="async"<?php if ($dimensions['width'] > 0): ?> width="<?php echo esc_attr((string) $dimensions['width']); ?>"<?php endif; ?><?php if ($dimensions['height'] > 0): ?> height="<?php echo esc_attr((string) $dimensions['height']); ?>"<?php endif; ?>><?php else: ?><span class="homepage-visual-fallback homepage-visual-fallback--video" aria-hidden="true"><span class="homepage-visual-icon">▶</span><small>Video</small></span><?php endif; ?><span class="video-play" aria-hidden="true">▶</span><span class="screen-reader-text">Phát video</span></span><span class="video-card-copy"><strong><?php echo esc_html(nhk_v3_public_brand_text($title)); ?></strong><?php if ($context !== ''): ?><small><?php echo esc_html(nhk_v3_public_copy($context)); ?></small><?php endif; ?><?php if ($date !== ''): ?><time datetime="<?php echo esc_attr($date); ?>"><?php echo esc_html(wp_date(get_option('date_format'), strtotime($date))); ?></time><?php endif; ?></span><?php if ($url !== ''): ?></a><?php endif; ?></article>
