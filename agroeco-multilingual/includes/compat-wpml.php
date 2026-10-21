<?php
/**
 * Сумісність із API WPML: функції icl_* та фільтри wpml_*, які часто
 * використовуються в темах. Підключається лише тоді, коли WPML вимкнено.
 */

defined( 'ABSPATH' ) || exit;

$agml = agml();

if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
	$agml_languages = $agml->active_languages();
	$agml_current   = $agml->content_language();

	define( 'ICL_LANGUAGE_CODE', $agml_current );
	define( 'ICL_LANGUAGE_NAME', isset( $agml_languages[ $agml_current ] ) ? $agml_languages[ $agml_current ]['native_name'] : $agml_current );
	define( 'ICL_LANGUAGE_NAME_EN', isset( $agml_languages[ $agml_current ] ) ? $agml_languages[ $agml_current ]['english_name'] : $agml_current );
	unset( $agml_languages, $agml_current );
}

/* ----------------------------------------------------------------------
 * Функції плагіна
 * ---------------------------------------------------------------------- */

/**
 * Виводить перемикач мов.
 *
 * @param array $args style (list|dropdown), flags, names, hide_current, skip_missing.
 */
function agml_language_switcher( $args = array() ) {
	echo agml()->switcher->render( $args ); // phpcs:ignore WordPress.Security.EscapeOutput
}

function agml_current_language() {
	return agml()->content_language();
}

/* ----------------------------------------------------------------------
 * Функції icl_* (застарілий API WPML, досі поширений у темах)
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'icl_object_id' ) ) {
	function icl_object_id( $element_id, $element_type = 'post', $return_original_if_missing = false, $language_code = null ) {
		return agml()->object_id( $element_id, $element_type, $return_original_if_missing, $language_code );
	}
}

if ( ! function_exists( 'icl_get_languages' ) ) {
	/**
	 * @param string|array $args Наприклад 'skip_missing=0&orderby=code'.
	 */
	function icl_get_languages( $args = '' ) {
		$args  = wp_parse_args(
			$args,
			array(
				'skip_missing' => 0,
				'orderby'      => 'custom',
				'order'        => 'asc',
			)
		);
		$links = agml()->switcher->get_links( (bool) $args['skip_missing'] );

		$result = array();
		$id     = 0;
		foreach ( $links as $code => $link ) {
			$result[ $code ] = array(
				'id'               => ++$id,
				'active'           => $link['active'] ? 1 : 0,
				'native_name'      => $link['native_name'],
				'missing'          => $link['missing'] ? 1 : 0,
				'translated_name'  => $link['translated_name'],
				'language_code'    => $code,
				'code'             => $code,
				'country_flag_url' => $link['flag_url'],
				'url'              => $link['url'],
				'default_locale'   => $link['locale'],
				'tag'              => $link['tag'],
			);
		}

		if ( 'code' === $args['orderby'] ) {
			ksort( $result );
		} elseif ( 'name' === $args['orderby'] ) {
			uasort(
				$result,
				static function ( $a, $b ) {
					return strcmp( $a['translated_name'], $b['translated_name'] );
				}
			);
		}
		if ( 'desc' === strtolower( $args['order'] ) ) {
			$result = array_reverse( $result, true );
		}

		return $result;
	}
}

if ( ! function_exists( 'icl_get_home_url' ) ) {
	function icl_get_home_url() {
		return home_url( '/' );
	}
}

if ( ! function_exists( 'icl_get_default_language' ) ) {
	function icl_get_default_language() {
		return agml()->default_language();
	}
}

if ( ! function_exists( 'icl_t' ) ) {
	function icl_t( $context, $name, $original_value = false ) {
		return agml()->strings->translate( $original_value, $context, $name );
	}
}

if ( ! function_exists( 'icl_translate' ) ) {
	function icl_translate( $context, $name, $original_value = false ) {
		return icl_t( $context, $name, $original_value );
	}
}

if ( ! function_exists( 'icl_register_string' ) ) {
	function icl_register_string( $context, $name, $value ) {
		agml()->db->register_string( $context, $name, $value, agml()->default_language() );
	}
}

if ( ! function_exists( 'icl_link_to_element' ) ) {
	function icl_link_to_element( $element_id, $element_type = 'post', $link_text = '', $optional_parameters = array(), $anchor = '', $echoit = true ) {
		$id = agml()->object_id( $element_id, $element_type, true );
		if ( taxonomy_exists( $element_type ) ) {
			$url   = get_term_link( (int) $id, $element_type );
			$url   = is_wp_error( $url ) ? '' : $url;
			$title = $link_text ? $link_text : get_term( (int) $id, $element_type )->name;
		} else {
			$url   = get_permalink( $id );
			$title = $link_text ? $link_text : get_the_title( $id );
		}
		if ( $optional_parameters ) {
			$url = add_query_arg( $optional_parameters, $url );
		}
		if ( $anchor ) {
			$url .= '#' . $anchor;
		}
		$html = '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
		if ( $echoit ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $html;
	}
}

/* ----------------------------------------------------------------------
 * Фільтри та дії wpml_* (сучасний API WPML)
 * ---------------------------------------------------------------------- */

add_filter(
	'wpml_current_language',
	static function () {
		return agml()->content_language();
	}
);

add_filter(
	'wpml_default_language',
	static function () {
		return agml()->default_language();
	}
);

add_filter(
	'wpml_active_languages',
	static function ( $empty = null, $args = '' ) {
		return icl_get_languages( $args );
	},
	10,
	2
);

add_filter(
	'wpml_object_id',
	static function ( $element_id, $element_type = 'post', $return_original = false, $lang = null ) {
		return agml()->object_id( $element_id, $element_type, $return_original, $lang );
	},
	10,
	4
);

add_filter(
	'wpml_element_language_code',
	static function ( $language, $args ) {
		$args = (array) $args;
		if ( empty( $args['element_id'] ) ) {
			return $language;
		}
		$type = isset( $args['element_type'] ) ? $args['element_type'] : 'post';
		if ( taxonomy_exists( $type ) ) {
			$term = get_term( (int) $args['element_id'], $type );
			return $term && ! is_wp_error( $term ) ? agml()->db->get_element_language( $term->term_taxonomy_id, 'tax_' . $type ) : $language;
		}
		$element_type = 0 === strpos( $type, 'post_' ) || 0 === strpos( $type, 'tax_' ) ? $type : 'post_' . $type;
		$lang         = agml()->db->get_element_language( (int) $args['element_id'], $element_type );
		return $lang ? $lang : $language;
	},
	10,
	2
);

add_filter(
	'wpml_post_language_details',
	static function ( $details, $post_id = null ) {
		$post_id = $post_id ? $post_id : get_the_ID();
		$lang    = agml()->get_post_language( $post_id );
		if ( ! $lang ) {
			return $details;
		}
		$languages = agml()->db->get_all_languages();
		$language  = isset( $languages[ $lang ] ) ? $languages[ $lang ] : null;
		return array(
			'language_code'      => $lang,
			'locale'             => $language ? $language['locale'] : $lang,
			'text_direction'     => false,
			'display_name'       => agml()->db->get_language_name( $lang, agml()->content_language() ),
			'native_name'        => $language ? $language['native_name'] : $lang,
			'different_language' => agml()->content_language() !== $lang,
		);
	},
	10,
	2
);

add_filter(
	'wpml_translate_single_string',
	static function ( $value, $context, $name, $lang = null ) {
		return agml()->strings->translate( $value, $context, $name, $lang );
	},
	10,
	4
);

add_action(
	'wpml_register_single_string',
	static function ( $context, $name, $value ) {
		agml()->db->register_string( $context, $name, $value, agml()->default_language() );
	},
	10,
	3
);

add_filter(
	'wpml_home_url',
	static function () {
		return home_url( '/' );
	}
);

add_filter(
	'wpml_permalink',
	static function ( $url, $lang = null ) {
		return agml()->router->convert_url( $url, $lang ? $lang : agml()->content_language() );
	},
	10,
	2
);

add_filter(
	'wpml_element_trid',
	static function ( $trid, $element_id, $element_type = 'post_post' ) {
		$row = agml()->db->get_element( (int) $element_id, $element_type );
		return $row ? (int) $row['trid'] : $trid;
	},
	10,
	3
);

add_filter(
	'wpml_get_element_translations',
	static function ( $translations, $trid ) {
		$result = array();
		foreach ( agml()->db->get_translations_by_trid( $trid ) as $code => $row ) {
			$result[ $code ] = (object) array(
				'translation_id'       => $row['translation_id'],
				'language_code'        => $code,
				'element_id'           => $row['element_id'],
				'source_language_code' => $row['source_language_code'],
				'element_type'         => $row['element_type'],
				'original'             => empty( $row['source_language_code'] ) ? 1 : 0,
			);
		}
		return $result;
	},
	10,
	2
);

add_action(
	'wpml_switch_language',
	static function ( $lang = null ) {
		static $original = null;
		if ( null === $original ) {
			$original = agml()->current_language();
		}
		agml()->router->set_current_language( $lang ? $lang : $original );
	}
);

add_action(
	'wpml_add_language_selector',
	static function () {
		agml_language_switcher( array( 'style' => 'dropdown' ) );
	}
);
