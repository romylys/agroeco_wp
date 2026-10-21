<?php
/**
 * Переклад рядків, збережених модулем WPML String Translation
 * (таблиці icl_strings та icl_string_translations).
 */

defined( 'ABSPATH' ) || exit;

class AGML_Strings {

	/** @var AGML_Plugin */
	private $plugin;

	/** @var array<string,array> Завантажені контексти: "context|lang" => strings. */
	private $loaded = array();

	/** @var array<string,bool>|null Контексти, що мають переклади. */
	private $contexts;

	public function __construct( AGML_Plugin $plugin ) {
		$this->plugin = $plugin;

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		add_filter( 'option_blogname', array( $this, 'translate_blogname' ) );
		add_filter( 'option_blogdescription', array( $this, 'translate_blogdescription' ) );
		add_filter( 'widget_title', array( $this, 'translate_widget_title' ) );

		if ( $plugin->settings->get( 'translate_gettext' ) ) {
			add_filter( 'gettext', array( $this, 'translate_gettext' ), 20, 3 );
			add_filter( 'gettext_with_context', array( $this, 'translate_gettext_with_context' ), 20, 4 );
		}
	}

	/**
	 * Переклад рядка: спочатку за назвою, потім за оригінальним значенням.
	 */
	public function translate( $value, $context, $name = '', $lang = null ) {
		$lang = $lang ? $lang : $this->plugin->current_language();
		if ( ! $lang || 'all' === $lang || ! $this->has_context( $context ) ) {
			return $value;
		}

		$key = $context . '|' . $lang;
		if ( ! isset( $this->loaded[ $key ] ) ) {
			$this->loaded[ $key ] = $this->plugin->db->get_context_strings( $context, $lang );
		}

		if ( '' !== $name && isset( $this->loaded[ $key ]['by_name'][ $name ] ) ) {
			return $this->loaded[ $key ]['by_name'][ $name ];
		}
		if ( is_string( $value ) && isset( $this->loaded[ $key ]['by_value'][ $value ] ) ) {
			return $this->loaded[ $key ]['by_value'][ $value ];
		}
		return $value;
	}

	private function has_context( $context ) {
		global $wpdb;

		if ( null === $this->contexts ) {
			$this->contexts = wp_cache_get( 'contexts', 'agml' );
			if ( ! is_array( $this->contexts ) ) {
				$this->contexts = array();
				if ( $this->plugin->db->strings_tables_exist() ) {
					$rows = $wpdb->get_col(
						"SELECT DISTINCT s.context FROM {$this->plugin->db->table( 'strings' )} s
						INNER JOIN {$this->plugin->db->table( 'string_translations' )} st ON st.string_id = s.id
						WHERE st.status = 10"
					);
					$this->contexts = array_fill_keys( (array) $rows, true );
				}
				wp_cache_set( 'contexts', $this->contexts, 'agml', HOUR_IN_SECONDS );
			}
		}
		return isset( $this->contexts[ $context ] );
	}

	public function translate_blogname( $value ) {
		return $this->translate( $value, 'WP', 'Blog Title' );
	}

	public function translate_blogdescription( $value ) {
		return $this->translate( $value, 'WP', 'Tagline' );
	}

	public function translate_widget_title( $title ) {
		return $this->translate( $title, 'Widgets', 'widget title - ' . md5( (string) $title ) );
	}

	public function translate_gettext( $translation, $text, $domain ) {
		$translated = $this->translate( $text, $domain );
		return $translated === $text ? $translation : $translated;
	}

	public function translate_gettext_with_context( $translation, $text, $context, $domain ) {
		return $this->translate_gettext( $translation, $text, $domain );
	}
}
