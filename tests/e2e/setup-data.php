<?php
/**
 * Наповнює чисту базу WordPress даними у форматі WPML (таблиці icl_*, сторінки з однаковими slug,
 * рубрики, меню, рядки String Translation) і активує плагін. Викликається з tests/e2e/run.sh.
 *
 * Змінні середовища: AGML_WP_DIR, AGML_HOST, AGML_ADMIN_PASS.
 */
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST']=getenv('AGML_HOST'); $_SERVER['REQUEST_URI']='/';
require getenv('AGML_WP_DIR').'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
global $wpdb;
add_filter('pre_wp_mail', '__return_false'); // без листа «сайт встановлено»
wp_install('Агроеко', 'admin', 'admin@example.com', true, '', getenv('AGML_ADMIN_PASS'));
update_option('siteurl','http://'.getenv('AGML_HOST')); update_option('home','http://'.getenv('AGML_HOST'));
update_option('permalink_structure','/%postname%/');
update_option('template','agtest'); update_option('stylesheet','agtest');
$p = $wpdb->prefix.'icl_';
// --- WPML-like tables (schema as WPML 4.x, incl. extra columns) ---
$wpdb->query("CREATE TABLE {$p}translations (translation_id bigint(20) NOT NULL AUTO_INCREMENT, element_type varchar(60) NOT NULL DEFAULT 'post_post', element_id bigint(20) DEFAULT NULL, trid bigint(20) NOT NULL, language_code varchar(7) NOT NULL, source_language_code varchar(7) DEFAULT NULL, PRIMARY KEY (translation_id), UNIQUE KEY trid_lang (trid,language_code), UNIQUE KEY el_type_id (element_type,element_id), KEY trid (trid), KEY id_type_language (element_id,element_type,language_code))");
$wpdb->query("CREATE TABLE {$p}languages (id int(11) NOT NULL AUTO_INCREMENT, code varchar(7) NOT NULL, english_name varchar(128) NOT NULL, major tinyint(4) NOT NULL DEFAULT 0, active tinyint(4) NOT NULL, default_locale varchar(35) DEFAULT NULL, tag varchar(35) DEFAULT NULL, encode_url tinyint(1) NOT NULL DEFAULT 0, country varchar(10) DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY code (code))");
$wpdb->query("CREATE TABLE {$p}languages_translations (id int(11) NOT NULL AUTO_INCREMENT, language_code varchar(7) NOT NULL, display_language_code varchar(7) NOT NULL, name varchar(255) NOT NULL, PRIMARY KEY (id), UNIQUE KEY language_code (language_code,display_language_code))");
$wpdb->query("CREATE TABLE {$p}flags (id int(11) NOT NULL AUTO_INCREMENT, lang_code varchar(10) NOT NULL, flag varchar(32) NOT NULL, from_template tinyint(4) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY lang_code (lang_code))");
$wpdb->query("CREATE TABLE {$p}strings (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, language varchar(7) NOT NULL, context varchar(160) NOT NULL, name varchar(160) NOT NULL, value longtext NOT NULL, string_package_id bigint(20) unsigned DEFAULT NULL, location bigint(20) unsigned DEFAULT NULL, wrap_tag varchar(16) NOT NULL DEFAULT '', type varchar(40) NOT NULL DEFAULT 'LINE', title varchar(160) DEFAULT NULL, status tinyint(4) NOT NULL, gettext_context text NOT NULL, domain_name_context_md5 varchar(32) NOT NULL, translation_priority varchar(160) NOT NULL DEFAULT '', PRIMARY KEY (id), UNIQUE KEY uc_domain_name_context_md5 (domain_name_context_md5))");
$wpdb->query("CREATE TABLE {$p}string_translations (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, string_id bigint(20) unsigned NOT NULL, language varchar(10) NOT NULL, status tinyint(4) NOT NULL, value longtext DEFAULT NULL, mo_string longtext DEFAULT NULL, translator_id bigint(20) unsigned DEFAULT NULL, translation_service varchar(16) NOT NULL DEFAULT '', batch_id int(11) NOT NULL DEFAULT 0, translation_date timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (id), UNIQUE KEY string_language (string_id,language))");
foreach (array(array('uk','Ukrainian',1,'uk','uk'),array('en','English',1,'en_US','en'),array('de','German',0,'de_DE','de')) as $l) {
  $wpdb->insert("{$p}languages", array('code'=>$l[0],'english_name'=>$l[1],'active'=>$l[2],'default_locale'=>$l[3],'tag'=>$l[4],'major'=>1));
  $wpdb->insert("{$p}flags", array('lang_code'=>$l[0],'flag'=>$l[0].'.png','from_template'=>0));
}
foreach (array(array('uk','uk','Українська'),array('en','en','English'),array('uk','en','Ukrainian'),array('en','uk','Англійська'),array('de','de','Deutsch')) as $t)
  $wpdb->insert("{$p}languages_translations", array('language_code'=>$t[0],'display_language_code'=>$t[1],'name'=>$t[2]));
update_option('icl_sitepress_settings', array('default_language'=>'uk','language_negotiation_type'=>1,'urls'=>array('directory_for_default_language'=>0),'custom_posts_sync_option'=>array('post'=>1,'page'=>1)));
$tr = function($id,$type,$lang,$trid,$src=null) use ($wpdb,$p){ $wpdb->insert("{$p}translations", array('element_id'=>$id,'element_type'=>$type,'language_code'=>$lang,'trid'=>$trid,'source_language_code'=>$src)); };
// Remove default content
foreach (get_posts(array('post_type'=>'any','post_status'=>'any','numberposts'=>-1)) as $x) wp_delete_post($x->ID, true);
// Pages
$home_uk = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Головна','post_name'=>'home'));
$home_en = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Home EN','post_name'=>'home-2'));
$about_uk = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Про нас','post_name'=>'about'));
$about_en = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'About us','post_name'=>'about'));
$wpdb->update($wpdb->posts, array('post_name'=>'about'), array('ID'=>$about_en)); // WPML allows same slug
$team_uk = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Команда','post_name'=>'team','post_parent'=>$about_uk));
$team_en = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Team','post_name'=>'team','post_parent'=>$about_en));
$wpdb->update($wpdb->posts, array('post_name'=>'team'), array('ID'=>$team_en));
$tr($home_uk,'post_page','uk',1); $tr($home_en,'post_page','en',1,'uk');
$tr($about_uk,'post_page','uk',2); $tr($about_en,'post_page','en',2,'uk');
$tr($team_uk,'post_page','uk',3); $tr($team_en,'post_page','en',3,'uk');
update_option('show_on_front','page'); update_option('page_on_front',$home_uk);
$blog_uk = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Новини','post_name'=>'news'));
$blog_en = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'News','post_name'=>'news-en'));
$tr($blog_uk,'post_page','uk',4); $tr($blog_en,'post_page','en',4,'uk');
update_option('page_for_posts',$blog_uk);
// Categories
$c_uk = wp_insert_term('Агротехнології','category',array('slug'=>'agro'));
$c_en = wp_insert_term('Agrotech','category',array('slug'=>'agro-en'));
$tr($c_uk['term_taxonomy_id'],'tax_category','uk',10); $tr($c_en['term_taxonomy_id'],'tax_category','en',10,'uk');
$uncat = get_term_by('slug','uncategorized','category'); $tr($uncat->term_taxonomy_id,'tax_category','uk',11);
// Posts
$p_uk = wp_insert_post(array('post_status'=>'publish','post_title'=>'Перша новина','post_name'=>'first','post_category'=>array($c_uk['term_id'])));
$p_en = wp_insert_post(array('post_status'=>'publish','post_title'=>'First news','post_name'=>'first-en','post_category'=>array($c_en['term_id'])));
$p_uk_only = wp_insert_post(array('post_status'=>'publish','post_title'=>'Лише українською','post_name'=>'uk-only','post_category'=>array($c_uk['term_id'])));
$tr($p_uk,'post_post','uk',20); $tr($p_en,'post_post','en',20,'uk'); $tr($p_uk_only,'post_post','uk',21);
// Menus
$m_uk = wp_create_nav_menu('Головне меню'); $m_en = wp_create_nav_menu('Main menu');
wp_update_nav_menu_item($m_uk, 0, array('menu-item-title'=>'Про нас','menu-item-object'=>'page','menu-item-object-id'=>$about_uk,'menu-item-type'=>'post_type','menu-item-status'=>'publish'));
wp_update_nav_menu_item($m_en, 0, array('menu-item-title'=>'About us','menu-item-object'=>'page','menu-item-object-id'=>$about_en,'menu-item-type'=>'post_type','menu-item-status'=>'publish'));
$mt_uk = get_term($m_uk,'nav_menu'); $mt_en = get_term($m_en,'nav_menu');
$wpdb->delete("{$p}translations", array('element_type'=>'tax_nav_menu'));
$tr($mt_uk->term_taxonomy_id,'tax_nav_menu','uk',30); $tr($mt_en->term_taxonomy_id,'tax_nav_menu','en',30,'uk');
set_theme_mod('nav_menu_locations', array('primary'=>$m_uk));
// Strings
$wpdb->insert("{$p}strings", array('language'=>'uk','context'=>'WP','name'=>'Blog Title','value'=>'Агроеко','status'=>2,'gettext_context'=>'','domain_name_context_md5'=>md5('WPBlog Title')));
$sid = $wpdb->insert_id; $wpdb->insert("{$p}string_translations", array('string_id'=>$sid,'language'=>'en','status'=>10,'value'=>'Agroeco'));
$wpdb->insert("{$p}strings", array('language'=>'uk','context'=>'agtest','name'=>md5('Read more'),'value'=>'Read more','status'=>2,'gettext_context'=>'','domain_name_context_md5'=>md5('agtestrm')));
$sid = $wpdb->insert_id; $wpdb->insert("{$p}string_translations", array('string_id'=>$sid,'language'=>'en','status'=>10,'value'=>'Read more EN'));
$wpdb->insert("{$p}string_translations", array('string_id'=>$sid,'language'=>'uk','status'=>10,'value'=>'Читати далі'));
// Activate plugin
$r = activate_plugin('agroeco-multilingual/agroeco-multilingual.php');
if (is_wp_error($r)) { fwrite(STDERR, 'Activation failed: ' . $r->get_error_message() . PHP_EOL); exit(1); }
$s = get_option('agml_settings'); $s['menu_switcher']['locations']=array('primary'); update_option('agml_settings',$s);
flush_rewrite_rules();
echo json_encode(compact('home_uk','home_en','about_uk','about_en','team_uk','team_en','blog_uk','blog_en','p_uk','p_en','p_uk_only','m_uk','m_en')), "\n";
