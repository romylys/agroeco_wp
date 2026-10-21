<?php
/**
 * Активація: перенесення налаштувань WPML, копіювання прапорів,
 * створення WPML-сумісних таблиць на сайтах, де WPML ніколи не був встановлений.
 */

defined( 'ABSPATH' ) || exit;

class AGML_Install {

	public static function activate() {
		self::create_tables();
		self::seed_languages();

		if ( false === get_option( AGML_Settings::OPTION ) ) {
			add_option( AGML_Settings::OPTION, AGML_Settings::from_wpml() );
		}

		self::copy_wpml_flags();
		wp_cache_delete( 'languages', 'agml' );
		wp_cache_delete( 'contexts', 'agml' );
	}

	/**
	 * Створює таблиці зі схемою WPML. Наявні таблиці WPML не змінюються.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'icl_';

		$tables = array(
			'translations'           => "CREATE TABLE {$p}translations (
				translation_id bigint(20) NOT NULL AUTO_INCREMENT,
				element_type varchar(60) NOT NULL DEFAULT 'post_post',
				element_id bigint(20) DEFAULT NULL,
				trid bigint(20) NOT NULL,
				language_code varchar(7) NOT NULL,
				source_language_code varchar(7) DEFAULT NULL,
				PRIMARY KEY  (translation_id),
				UNIQUE KEY trid_lang (trid,language_code),
				UNIQUE KEY el_type_id (element_type,element_id),
				KEY trid (trid),
				KEY id_type_language (element_id,element_type,language_code)
			) {$charset};",
			'languages'              => "CREATE TABLE {$p}languages (
				id int(11) NOT NULL AUTO_INCREMENT,
				code varchar(7) NOT NULL,
				english_name varchar(128) NOT NULL,
				major tinyint(4) NOT NULL DEFAULT 0,
				active tinyint(4) NOT NULL DEFAULT 0,
				default_locale varchar(35) DEFAULT NULL,
				tag varchar(35) DEFAULT NULL,
				encode_url tinyint(1) NOT NULL DEFAULT 0,
				country varchar(10) DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				UNIQUE KEY english_name (english_name)
			) {$charset};",
			'languages_translations' => "CREATE TABLE {$p}languages_translations (
				id int(11) NOT NULL AUTO_INCREMENT,
				language_code varchar(7) NOT NULL,
				display_language_code varchar(7) NOT NULL,
				name varchar(255) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY language_code (language_code,display_language_code)
			) {$charset};",
			'flags'                  => "CREATE TABLE {$p}flags (
				id int(11) NOT NULL AUTO_INCREMENT,
				lang_code varchar(10) NOT NULL,
				flag varchar(32) NOT NULL,
				from_template tinyint(4) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY lang_code (lang_code)
			) {$charset};",
			'strings'                => "CREATE TABLE {$p}strings (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				language varchar(7) NOT NULL,
				context varchar(160) NOT NULL,
				name varchar(160) NOT NULL,
				value longtext NOT NULL,
				status tinyint(4) NOT NULL DEFAULT 0,
				gettext_context text NOT NULL,
				domain_name_context_md5 varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY uc_domain_name_context_md5 (domain_name_context_md5),
				KEY context (context)
			) {$charset};",
			'string_translations'    => "CREATE TABLE {$p}string_translations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				string_id bigint(20) unsigned NOT NULL,
				language varchar(10) NOT NULL,
				status tinyint(4) NOT NULL,
				value longtext DEFAULT NULL,
				translation_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY string_language (string_id,language)
			) {$charset};",
		);

		foreach ( $tables as $name => $sql ) {
			$table = $p . $name;
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				dbDelta( $sql );
			}
		}
	}

	/**
	 * Базовий набір мов для сайту без даних WPML.
	 */
	private static function seed_languages() {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_languages';
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$languages = array(
			// code => [english, native, locale, tag, active].
			'uk' => array( 'Ukrainian', 'Українська', 'uk', 'uk', 1 ),
			'en' => array( 'English', 'English', 'en_US', 'en', 1 ),
			'pl' => array( 'Polish', 'Polski', 'pl_PL', 'pl', 0 ),
			'de' => array( 'German', 'Deutsch', 'de_DE', 'de', 0 ),
			'fr' => array( 'French', 'Français', 'fr_FR', 'fr', 0 ),
			'es' => array( 'Spanish', 'Español', 'es_ES', 'es', 0 ),
			'it' => array( 'Italian', 'Italiano', 'it_IT', 'it', 0 ),
		);

		foreach ( $languages as $code => $data ) {
			$wpdb->insert(
				$table,
				array(
					'code'           => $code,
					'english_name'   => $data[0],
					'major'          => 1,
					'active'         => $data[4],
					'default_locale' => $data[2],
					'tag'            => $data[3],
				)
			);
			$wpdb->insert(
				$wpdb->prefix . 'icl_languages_translations',
				array(
					'language_code'         => $code,
					'display_language_code' => $code,
					'name'                  => $data[1],
				)
			);
			if ( 'en' !== $code ) {
				$wpdb->insert(
					$wpdb->prefix . 'icl_languages_translations',
					array(
						'language_code'         => $code,
						'display_language_code' => 'en',
						'name'                  => $data[0],
					)
				);
			}
			$wpdb->insert(
				$wpdb->prefix . 'icl_flags',
				array(
					'lang_code'     => $code,
					'flag'          => $code . '.svg',
					'from_template' => 0,
				)
			);
		}
	}

	/**
	 * Копіює прапори, що використовуються, з каталогу WPML в uploads,
	 * щоб вони залишилися доступними після видалення WPML.
	 */
	public static function copy_wpml_flags() {
		global $wpdb;

		$source = WP_PLUGIN_DIR . '/sitepress-multilingual-cms/res/flags/';
		if ( ! is_dir( $source ) ) {
			return;
		}

		$uploads = wp_upload_dir( null, true );
		$target  = trailingslashit( $uploads['basedir'] ) . 'agroeco-multilingual/flags/';
		if ( ! wp_mkdir_p( $target ) ) {
			return;
		}

		$flags = $wpdb->get_col( "SELECT flag FROM {$wpdb->prefix}icl_flags WHERE from_template = 0" );
		foreach ( (array) $flags as $flag ) {
			$flag = basename( $flag );
			if ( '' !== $flag && file_exists( $source . $flag ) && ! file_exists( $target . $flag ) ) {
				copy( $source . $flag, $target . $flag );
			}
		}
	}
}
