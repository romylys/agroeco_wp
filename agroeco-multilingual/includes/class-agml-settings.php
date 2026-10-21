<?php
/**
 * Налаштування плагіна. Під час першого запуску значення переносяться
 * з налаштувань WPML (опція icl_sitepress_settings).
 */

defined( 'ABSPATH' ) || exit;

class AGML_Settings {

	const OPTION = 'agml_settings';

	const URL_DIRECTORY = 'directory';
	const URL_DOMAIN    = 'domain';
	const URL_PARAMETER = 'parameter';

	/** @var array */
	private $values;

	public function __construct() {
		$stored       = get_option( self::OPTION );
		$this->values = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function defaults() {
		return array(
			'default_language'     => 'uk',
			'url_format'           => self::URL_DIRECTORY,
			'default_in_directory' => false,
			'domains'              => array(),
			'post_types'           => array( 'post', 'page' ),
			'taxonomies'           => array( 'category', 'post_tag' ),
			'hreflang'             => true,
			'translate_gettext'    => true,
			'menu_switcher'        => array(
				'locations'    => array(),
				'style'        => 'dropdown', // dropdown | inline.
				'show_flag'    => true,
				'show_name'    => 'native', // none | native | translated | both | code.
				'hide_current' => false,
				'position'     => 'end', // start | end.
			),
		);
	}

	/**
	 * Будує налаштування з даних WPML (якщо вони є).
	 */
	public static function from_wpml() {
		$settings = self::defaults();
		$wpml     = get_option( 'icl_sitepress_settings' );

		if ( ! is_array( $wpml ) ) {
			return $settings;
		}

		if ( ! empty( $wpml['default_language'] ) ) {
			$settings['default_language'] = $wpml['default_language'];
		}

		$negotiation = isset( $wpml['language_negotiation_type'] ) ? (int) $wpml['language_negotiation_type'] : 1;
		if ( 2 === $negotiation ) {
			$settings['url_format'] = self::URL_DOMAIN;
		} elseif ( 3 === $negotiation ) {
			$settings['url_format'] = self::URL_PARAMETER;
		}

		if ( ! empty( $wpml['urls']['directory_for_default_language'] ) ) {
			$settings['default_in_directory'] = true;
		}

		if ( ! empty( $wpml['language_domains'] ) && is_array( $wpml['language_domains'] ) ) {
			$settings['domains'] = $wpml['language_domains'];
		}

		// 1 = перекладається, 2 = показувати оригінал, якщо перекладу немає.
		if ( ! empty( $wpml['custom_posts_sync_option'] ) && is_array( $wpml['custom_posts_sync_option'] ) ) {
			foreach ( $wpml['custom_posts_sync_option'] as $type => $mode ) {
				if ( (int) $mode > 0 && ! in_array( $type, $settings['post_types'], true ) ) {
					$settings['post_types'][] = $type;
				}
			}
		}
		if ( ! empty( $wpml['taxonomies_sync_option'] ) && is_array( $wpml['taxonomies_sync_option'] ) ) {
			foreach ( $wpml['taxonomies_sync_option'] as $tax => $mode ) {
				if ( (int) $mode > 0 && ! in_array( $tax, $settings['taxonomies'], true ) ) {
					$settings['taxonomies'][] = $tax;
				}
			}
		}

		return $settings;
	}

	public function get( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}

	public function all() {
		return $this->values;
	}

	public function menu( $key ) {
		$menu = wp_parse_args( (array) $this->values['menu_switcher'], self::defaults()['menu_switcher'] );
		return isset( $menu[ $key ] ) ? $menu[ $key ] : null;
	}

	public function update( array $values ) {
		$this->values = wp_parse_args( $values, self::defaults() );
		update_option( self::OPTION, $this->values );
	}

	/**
	 * Типи записів, що перекладаються (меню-елементи та вкладення не фільтруються).
	 */
	public function post_types() {
		return array_values( array_diff( (array) $this->values['post_types'], array( 'nav_menu_item', 'attachment' ) ) );
	}

	/**
	 * Таксономії, що перекладаються (меню завжди перекладаються).
	 */
	public function taxonomies() {
		return array_values( array_unique( array_merge( (array) $this->values['taxonomies'], array( 'nav_menu' ) ) ) );
	}
}
