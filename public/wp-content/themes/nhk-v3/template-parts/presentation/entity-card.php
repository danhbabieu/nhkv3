<?php
$item = is_array($args['item'] ?? null) ? $args['item'] : [];
$url = nhk_v3_public_url($item['url'] ?? null);
$title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
if ($url === '' || $title === '') return;
$representative = is_array($item['representative'] ?? null) ? $item['representative'] : (is_array($item['media']['representative'] ?? null) ? $item['media']['representative'] : []);
$mediaItem = $representative !== [] ? $representative : $item;
$visual = nhk_v3_media_presentation($mediaItem);
$image = $visual['url'];
$alt = trim((string) ($item['image_alt'] ?? $mediaItem['alt'] ?? ''));
$label = trim((string) ($item['profile_label'] ?? $args['label'] ?? ''));
$counts = is_array($item['counts'] ?? null) ? $item['counts'] : [];
$countLabels = ['subtype_count' => 'nhóm con', 'article_count' => 'bài viết', 'media_count' => 'hình ảnh', 'video_count' => 'video'];
?>
<article class="entity-card visual-entity-card<?php echo $image === '' ? ' is-no-image' : ''; ?>">
  <?php if ($image !== ''): ?><a class="entity-card-image" href="<?php echo esc_url($url); ?>"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($alt); ?>" loading="lazy" decoding="async"<?php if ($visual['srcset'] !== ''): ?> srcset="<?php echo esc_attr($visual['srcset']); ?>"<?php endif; ?><?php if ($visual['sizes'] !== ''): ?> sizes="<?php echo esc_attr($visual['sizes']); ?>"<?php endif; ?><?php if ($visual['width'] > 0): ?> width="<?php echo esc_attr((string) $visual['width']); ?>"<?php endif; ?><?php if ($visual['height'] > 0): ?> height="<?php echo esc_attr((string) $visual['height']); ?>"<?php endif; ?>></a><?php endif; ?>
  <div class="entity-card-body">
    <?php if ($label !== ''): ?><p class="eyebrow"><?php echo esc_html($label); ?></p><?php endif; ?>
    <h2><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(nhk_v3_public_brand_text($title)); ?></a></h2>
    <?php if (trim((string) ($item['description'] ?? '')) !== ''): ?><p class="card-summary"><?php echo esc_html(wp_trim_words((string) $item['description'], 18)); ?></p><?php endif; ?>
    <?php $badges = []; foreach ($countLabels as $key => $countLabel) { $count = (int) ($counts[$key] ?? $item[$key] ?? 0); if ($count > 0) $badges[] = '<span>' . esc_html((string) $count) . ' ' . esc_html($countLabel) . '</span>'; } if ($badges !== []): ?><div class="count-badges" aria-label="Số lượng nội dung liên quan"><?php echo implode('', $badges); ?></div><?php endif; ?>
  </div>
</article>
