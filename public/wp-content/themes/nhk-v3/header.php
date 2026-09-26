<?php
$navGroups = nhk_v3_navigation_groups();
$primaryNav = is_array($navGroups['primary'] ?? null) ? $navGroups['primary'] : [];
$discoveryNav = is_array($navGroups['discovery'] ?? null) ? $navGroups['discovery'] : [];
?>
<!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?><a class="skip-link" href="#main-content">Bỏ qua đến nội dung chính</a><header class="site-header"><div class="header-inner">
  <a class="brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Đồng Hồ Nhà Kho - trang chủ"><span class="brand-mark">NHK</span><span class="brand-name">Đồng Hồ Nhà Kho</span></a>
  <input class="nav-toggle" type="checkbox" id="nav-toggle" aria-label="Mở menu điều hướng" aria-controls="primary-navigation" aria-expanded="false"><label class="nav-toggle-label" for="nav-toggle"><span></span><span></span><span></span><b class="screen-reader-text">Menu</b></label>
  <div class="header-actions">
    <nav class="nav" id="primary-navigation" aria-label="Điều hướng chính">
      <div class="nav-primary"><?php nhk_v3_render_nav_items($primaryNav); ?></div>
      <?php $typeNav = nhk_v3_clock_type_navigation_items('header_menu'); if ($typeNav !== []): ?><details class="nav-discovery nav-type-menu"><summary>LOẠI <span aria-hidden="true">⌄</span></summary><div class="nav-discovery-panel"><?php nhk_v3_render_nav_items($typeNav); ?></div></details><?php endif; ?>
      <?php $mobileTypeNav = nhk_v3_clock_type_navigation_items('mobile_menu'); if ($mobileTypeNav !== []): ?><details class="nav-discovery nav-type-menu-mobile"><summary>LOẠI trên điện thoại <span aria-hidden="true">⌄</span></summary><div class="nav-discovery-panel"><?php nhk_v3_render_nav_items($mobileTypeNav); ?></div></details><?php endif; ?>
      <?php if ($discoveryNav !== []): ?><details class="nav-discovery"><summary>Khám phá <span aria-hidden="true">⌄</span></summary><div class="nav-discovery-panel"><?php nhk_v3_render_nav_items($discoveryNav); ?></div></details><?php endif; ?>
    </nav>
    <?php if ($primaryNav === [] && $discoveryNav === []) nhk_v3_nav_fallback(); ?>
    <form class="global-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><label class="screen-reader-text" for="nhk-search">Tìm kiếm toàn hệ thống</label><input id="nhk-search" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="Tìm trong NHK..." /><button type="submit">Tìm</button></form>
  </div>
</div></header>
