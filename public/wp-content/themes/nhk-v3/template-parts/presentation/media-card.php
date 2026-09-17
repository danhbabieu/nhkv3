<?php
$item = is_array($args['item'] ?? null) ? $args['item'] : [];
$title = trim((string) ($item['title'] ?? $item['caption'] ?? $item['name'] ?? 'Hình ảnh'));
$image = trim((string) ($item['image_url'] ?? $item['url'] ?? $item['media']['url'] ?? ''));
$alt = trim((string) ($item['alt'] ?? $item['image_alt'] ?? $item['caption'] ?? $title));
$link = nhk_v3_public_url($item['detail_url'] ?? $item['page_url'] ?? $item['entity_url'] ?? $item['article_url'] ?? null);
$dimensions = nhk_v3_media_dimensions($item);
$orientationClass = nhk_v3_media_orientation_class($dimensions['width'], $dimensions['height']);
if ($title === '') return;
?><figure class="media-card visual-card <?php echo esc_attr($orientationClass); ?>"><?php if ($link !== ''): ?><a class="media-card-image" href="<?php echo esc_url($link); ?>" aria-label="<?php echo esc_attr($title); ?>"><?php endif; ?><?php if ($image !== ''): ?><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($alt); ?>" loading="lazy"<?php if ($dimensions['width'] > 0): ?> width="<?php echo esc_attr((string) $dimensions['width']); ?>"<?php endif; ?><?php if ($dimensions['height'] > 0): ?> height="<?php echo esc_attr((string) $dimensions['height']); ?>"<?php endif; ?>><?php else: ?><span class="image-placeholder" role="img" aria-label="Chưa có ảnh đại diện"></span><?php endif; ?><?php if ($link !== ''): ?></a><?php endif; ?><?php if ($title !== ''): ?><figcaption><?php echo esc_html(nhk_v3_public_brand_text($title)); ?></figcaption><?php endif; ?></figure>
