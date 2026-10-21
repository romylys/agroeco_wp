<?php
/**
 * Видалення плагіна. Таблиці icl_* НЕ видаляються: у них зберігаються переклади,
 * які можуть знадобитися WPML або повторно встановленому плагіну.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'agml_settings' );
delete_metadata( 'user', 0, 'agml_admin_lang', '', true );
