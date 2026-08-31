<!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?><header class="site-header"><div class="header-inner">
  <a class="brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Đồng Hồ Nhà Kho - trang chủ"><span class="brand-mark">NHK</span><span class="brand-name">Đồng Hồ Nhà Kho</span></a>
  <input class="nav-toggle" type="checkbox" id="nav-toggle" aria-label="Mở menu"><label class="nav-toggle-label" for="nav-toggle"><span></span><span></span><span></span><b class="screen-reader-text">Menu</b></label>
  <div class="header-actions"><nav class="nav" aria-label="Điều hướng chính"><?php if (has_nav_menu('primary')) wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'menu_class' => 'nav-list']); else nhk_v3_nav_fallback(); ?></nav>
  <form class="global-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><label class="screen-reader-text" for="nhk-search">Tìm kiếm toàn hệ thống</label><input id="nhk-search" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="Tìm bài viết, thương hiệu, mẫu..." /><button type="submit">Tìm</button></form></div>
</div></header>
