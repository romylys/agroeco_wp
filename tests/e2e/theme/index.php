<!doctype html><html <?php language_attributes(); ?>><head><?php wp_head(); ?></head><body <?php body_class(); ?>>
<div id="lang">LANG=<?php echo ICL_LANGUAGE_CODE; ?> LOCALE=<?php echo get_locale(); ?></div>
<div id="blogname">BLOG=<?php bloginfo('name'); ?></div>
<div id="gettext">GT=<?php echo esc_html__('Read more', 'agtest'); ?></div>
<nav><?php wp_nav_menu(array('theme_location'=>'primary','container'=>false)); ?></nav>
<main>
<?php if (is_404()) echo "IS404\n"; ?>
<?php while (have_posts()) { the_post(); echo '<article>TITLE=' . get_the_title() . ' LINK=' . get_permalink() . ' CATS=' . strip_tags(get_the_category_list(',')) . "</article>\n"; } ?>
</main>
<div id="cats">ALLCATS=<?php echo implode(',', wp_list_pluck(get_categories(array('hide_empty'=>false)), 'name')); ?></div>
<div id="sw"><?php echo do_shortcode('[agml_language_switcher]'); ?></div>
<div id="icl">ICL=<?php echo json_encode(array_map(function($l){return $l['url'];}, icl_get_languages('skip_missing=0'))); ?> OBJ=<?php echo (int) apply_filters('wpml_object_id', 2, 'page', true); ?></div>
</body></html>
