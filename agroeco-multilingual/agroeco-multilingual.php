<?php
/**
 * Plugin Name:       Agroeco Multilingual
 * Description:       Легкий плагін багатомовності, сумісний із даними WPML (таблиці icl_*). Дозволяє відмовитися від WPML Multilingual CMS та WPML Flag in Menu Extended без повторного створення перекладів.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Agroeco
 * License:           GPL-2.0-or-later
 * Text Domain:       agroeco-multilingual
 */

defined( 'ABSPATH' ) || exit;

define( 'AGML_VERSION', '1.0.0' );
define( 'AGML_FILE', __FILE__ );
define( 'AGML_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGML_URL', plugin_dir_url( __FILE__ ) );

require_once AGML_DIR . 'includes/class-agml-db.php';
require_once AGML_DIR . 'includes/class-agml-settings.php';
require_once AGML_DIR . 'includes/class-agml-router.php';
require_once AGML_DIR . 'includes/class-agml-query.php';
require_once AGML_DIR . 'includes/class-agml-strings.php';
require_once AGML_DIR . 'includes/class-agml-switcher.php';
require_once AGML_DIR . 'includes/class-agml-admin.php';
require_once AGML_DIR . 'includes/class-agml-plugin.php';
require_once AGML_DIR . 'includes/class-agml-install.php';

register_activation_hook( __FILE__, array( 'AGML_Install', 'activate' ) );

add_action( 'plugins_loaded', array( 'AGML_Plugin', 'boot' ), 1 );

/**
 * Головний екземпляр плагіна.
 *
 * @return AGML_Plugin|null
 */
function agml() {
	return AGML_Plugin::instance();
}
