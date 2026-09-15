<?php
$item = is_array($args['item'] ?? null) ? $args['item'] : [];
$url = nhk_v3_public_url($item['url'] ?? null);
$title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
if ($url === '' || $title === '') return;
$image = trim((string) ($item['image_url'] ?? $item['media']['representative']['url'] ?? ''));
$alt = trim((string) ($item['image_alt'] ?? $item['media']['representative']['alt'] ?? ''));
$label = trim((string) ($item['profile_label'] ?? $args['label'] ?? ''));
$counts = is_array($item['counts'] ?? null) ? $item['counts'] : [];
$countLabels = ['subtype_count' => 'nhóm con', 'article_count' => 'bài viết', 'media_count' => 'hình ảnh', 'video_count' => 'video'];
?>
<article class="entity-card visual-entity-card"><a class="entity-card-image" href="<?php echo esc_url($url); ?>"><?php if ($image !== ''): ?><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($alt); ?>" loading="lazy"><?php else: ?><span class="image-placeholder" aria-hidden="true"></span><?php endif; ?></a><div class="entity-card-body"><?php if ($label !== ''): ?><p class="eyebrow"><?php echo esc_html($label); ?></p><?php endif; ?><h2><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(nhk_v3_public_brand_text($title)); ?></a></h2><?php if (trim((string) ($item['description'] ?? '')) !== ''): ?><p class="card-summary"><?php echo esc_html(wp_trim_words((string) $item['description'], 18)); ?></p><?php endif; ?><?php $badges = []; foreach ($countLabels as $key => $countLabel) { $count = (int) ($counts[$key] ?? $item[$key] ?? 0); if ($count > 0) $badges[] = '<span>' . esc_html((string) $count) . ' ' . esc_html($countLabel) . '</span>'; } if ($badges !== []): ?><div class="count-badges" aria-label="Số lượng nội dung liên quan"><?php echo implode('', $badges); ?></div><?php endif; ?></div></article>
