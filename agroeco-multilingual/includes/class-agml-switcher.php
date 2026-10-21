<?php
/**
 * Перемикач мов: пункти меню з прапорами (замість WPML Flag in Menu Extended),
 * шорткод [agml_language_switcher], функція шаблону та теги hreflang.
 */

defined( 'ABSPATH' ) || exit;

class AGML_Switcher {

	/** @var AGML_Plugin */
	private $plugin;

	/** @var array|null */
	private $links;

	public function __construct( AGML_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_shortcode( 'agml_language_switcher', array( $this, 'shortcode' ) );

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		add_filter( 'wp_nav_menu_objects', array( $this, 'add_menu_items' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_head', array( $this, 'hreflang' ), 5 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	public function enqueue() {
		wp_enqueue_style( 'agml-switcher', AGML_URL . 'assets/agml.css', array(), AGML_VERSION );
	}

	public function body_class( $classes ) {
		$classes[] = 'lang-' . sanitize_html_class( $this->plugin->current_language() );
		return $classes;
	}

	/* ------------------------------------------------------------------
	 * Посилання на поточну сторінку іншими мовами
	 * ------------------------------------------------------------------ */

	/**
	 * Активні мови з посиланням на переклад поточної сторінки.
	 *
	 * @param bool $skip_missing Пропускати мови, для яких немає перекладу.
	 * @return array<string,array> code => [code, url, native_name, translated_name, english_name, flag_url, active, missing, locale, tag]
	 */
	public function get_links( $skip_missing = false ) {
		if ( null === $this->links ) {
			$this->links = $this->build_links();
		}

		if ( ! $skip_missing ) {
			return $this->links;
		}
		return array_filter(
			$this->links,
			static function ( $link ) {
				return ! $link['missing'];
			}
		);
	}

	private function build_links() {
		$current   = $this->plugin->current_language();
		$languages = $this->plugin->active_languages();
		$targets   = $this->translated_urls();
		$links     = array();

		foreach ( $languages as $code => $language ) {
			$missing = false;
			if ( null === $targets ) {
				$url = $this->plugin->router->convert_url( $this->current_url(), $code );
			} elseif ( isset( $targets[ $code ] ) ) {
				$url = $targets[ $code ];
			} else {
				// Перекладу немає — ведемо на головну сторінку відповідної мови.
				$url     = $this->plugin->router->convert_url( home_url( '/' ), $code );
				$missing = true;
			}

			$links[ $code ] = array(
				'code'            => $code,
				'url'             => $url,
				'native_name'     => $language['native_name'],
				'translated_name' => $this->plugin->db->get_language_name( $code, $current ),
				'english_name'    => $language['english_name'],
				'flag_url'        => $this->plugin->db->get_flag_url( $code ),
				'active'          => $code === $current,
				'missing'         => $missing,
				'locale'          => $language['locale'],
				'tag'             => $language['tag'],
			);
		}

		return apply_filters( 'agml_language_links', $links );
	}

	/**
	 * URL перекладів поточного об'єкта або null, якщо сторінка не прив'язана до об'єкта
	 * (тоді просто змінюємо мовну частину URL).
	 *
	 * @return array<string,string>|null
	 */
	private function translated_urls() {
		if ( is_front_page() || ( is_home() && ! is_paged() && 'posts' === get_option( 'show_on_front' ) ) ) {
			$urls = array();
			foreach ( array_keys( $this->plugin->active_languages() ) as $code ) {
				$urls[ $code ] = $this->plugin->router->convert_url( home_url( '/' ), $code );
			}
			return $urls;
		}

		$object = get_queried_object();

		if ( ( is_singular() || is_home() ) && $object instanceof WP_Post ) {
			if ( ! $this->plugin->is_translatable_post_type( $object->post_type ) ) {
				return null;
			}
			$urls = array();
			foreach ( $this->plugin->db->get_element_translations( $object->ID, 'post_' . $object->post_type ) as $code => $row ) {
				$post = get_post( (int) $row['element_id'] );
				if ( $post && ( 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID ) ) ) {
					$urls[ $code ] = get_permalink( $post );
				}
			}
			if ( empty( $urls ) ) {
				$urls[ $this->plugin->default_language() ] = get_permalink( $object );
			}
			return $urls;
		}

		if ( ( is_category() || is_tag() || is_tax() ) && $object instanceof WP_Term ) {
			if ( ! $this->plugin->is_translatable_taxonomy( $object->taxonomy ) ) {
				return null;
			}
			$urls = array();
			foreach ( $this->plugin->db->get_element_translations( $object->term_taxonomy_id, 'tax_' . $object->taxonomy ) as $code => $row ) {
				$term = get_term_by( 'term_taxonomy_id', (int) $row['element_id'], $object->taxonomy, OBJECT, 'raw' );
				if ( ! $term ) {
					$term = $this->term_by_tt_id( (int) $row['element_id'], $object->taxonomy );
				}
				if ( $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[ $code ] = $link;
					}
				}
			}
			return $urls;
		}

		return null;
	}

	private function term_by_tt_id( $tt_id, $taxonomy ) {
		global $wpdb;
		$term_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) );
		$term    = $term_id ? get_term( (int) $term_id, $taxonomy ) : null;
		return $term instanceof WP_Term ? $term : null;
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return esc_url_raw( $scheme . $host . $uri );
	}

	/* ------------------------------------------------------------------
	 * Відображення
	 * ------------------------------------------------------------------ */

	/**
	 * HTML мітки мови (прапор + назва).
	 */
	public function label( array $link, $show_flag = null, $show_name = null ) {
		$show_flag = null === $show_flag ? $this->plugin->settings->menu( 'show_flag' ) : $show_flag;
		$show_name = null === $show_name ? $this->plugin->settings->menu( 'show_name' ) : $show_name;

		$html = '';
		if ( $show_flag && $link['flag_url'] ) {
			$html .= sprintf(
				'<img class="agml-flag" src="%s" alt="%s" width="18" height="12" loading="lazy" />',
				esc_url( $link['flag_url'] ),
				'none' === $show_name ? esc_attr( $link['native_name'] ) : ''
			);
		}

		switch ( $show_name ) {
			case 'native':
				$name = $link['native_name'];
				break;
			case 'translated':
				$name = $link['translated_name'];
				break;
			case 'both':
				$name = $link['native_name'] . ( $link['native_name'] !== $link['translated_name'] ? ' (' . $link['translated_name'] . ')' : '' );
				break;
			case 'code':
				$name = strtoupper( $link['code'] );
				break;
			default:
				$name = '';
		}

		if ( '' !== $name ) {
			$html .= '<span class="agml-name">' . esc_html( $name ) . '</span>';
		} elseif ( '' === $html ) {
			$html = '<span class="agml-name">' . esc_html( strtoupper( $link['code'] ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * Додає мовні пункти до меню у вибраних розташуваннях теми.
	 */
	public function add_menu_items( $items, $args ) {
		$locations = (array) $this->plugin->settings->menu( 'locations' );
		if ( empty( $args->theme_location ) || ! in_array( $args->theme_location, $locations, true ) ) {
			return $items;
		}

		$links = $this->get_links();
		if ( count( $links ) < 2 ) {
			return $items;
		}

		$style        = $this->plugin->settings->menu( 'style' );
		$hide_current = (bool) $this->plugin->settings->menu( 'hide_current' );
		$current      = $this->plugin->current_language();
		$new          = array();
		$order        = 0;

		// Від'ємні числові ID не перетинаються зі справжніми пунктами меню і коректно
		// обробляються Walker_Nav_Menu (рядкові ID у PHP 7 дорівнюють 0 при нестрогому порівнянні).
		$next_id = -1000;

		if ( 'dropdown' === $style ) {
			$parent_id = $next_id--;
			$new[]     = $this->menu_item( $links[ $current ], $parent_id, 0, array( 'menu-item-has-children', 'agml-parent' ), ++$order );
			foreach ( $links as $code => $link ) {
				if ( $code !== $current ) {
					$new[] = $this->menu_item( $link, $next_id--, $parent_id, array(), ++$order );
				}
			}
		} else {
			foreach ( $links as $code => $link ) {
				if ( $hide_current && $code === $current ) {
					continue;
				}
				$new[] = $this->menu_item( $link, $next_id--, 0, array(), ++$order );
			}
		}

		$items = 'start' === $this->plugin->settings->menu( 'position' ) ? array_merge( $new, $items ) : array_merge( $items, $new );

		// Перенумеровуємо menu_order, щоб walker зберіг порядок.
		$i = 0;
		foreach ( $items as $item ) {
			$item->menu_order = ++$i;
		}
		return $items;
	}

	private function menu_item( array $link, $id, $parent, array $extra_classes, $order ) {
		$classes = array_merge(
			array( 'menu-item', 'agml-menu-item', 'agml-lang-' . $link['code'] ),
			$link['active'] ? array( 'current-lang', 'agml-current' ) : array(),
			$extra_classes
		);

		$item                   = new stdClass();
		$item->ID               = $id;
		$item->db_id            = $id;
		$item->menu_item_parent = $parent;
		$item->object_id        = $id;
		$item->object           = 'agml_language';
		$item->type             = 'custom';
		$item->type_label       = 'Language';
		$item->title            = $this->label( $link );
		$item->url              = $link['url'];
		$item->target           = '';
		$item->attr_title       = $link['native_name'];
		$item->description      = '';
		$item->classes          = $classes;
		$item->xfn              = 'alternate';
		$item->current          = false;
		$item->current_item_ancestor = false;
		$item->current_item_parent   = false;
		$item->menu_order       = $order;
		$item->post_type        = 'nav_menu_item';
		$item->post_parent      = 0;
		$item->status           = 'publish';
		$item->post_title       = $item->title;
		$item->post_content     = '';
		$item->post_excerpt     = '';
		$item->agml_lang        = $link['code'];

		return $item;
	}

	/**
	 * HTML перемикача для шорткоду та функції шаблону.
	 *
	 * @param array $atts style (list|dropdown), flags (1|0), names (native|translated|both|code|none), hide_current (1|0), skip_missing (1|0)
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'style'        => 'list',
				'flags'        => '1',
				'names'        => 'native',
				'hide_current' => '0',
				'skip_missing' => '0',
			),
			$atts,
			'agml_language_switcher'
		);

		$links = $this->get_links( '1' === (string) $atts['skip_missing'] );
		if ( count( $links ) < 2 && ! ( '1' === (string) $atts['hide_current'] && count( $links ) === 1 ) ) {
			return '';
		}

		$flags = '1' === (string) $atts['flags'];

		if ( 'dropdown' === $atts['style'] ) {
			$current = $this->plugin->current_language();
			$html    = '<div class="agml-switcher agml-dropdown">';
			if ( isset( $links[ $current ] ) ) {
				$html .= '<span class="agml-toggle">' . $this->label( $links[ $current ], $flags, $atts['names'] ) . '</span>';
			}
			$html .= '<ul>';
			foreach ( $links as $code => $link ) {
				if ( $code !== $current ) {
					$html .= $this->list_item( $link, $flags, $atts['names'] );
				}
			}
			return $html . '</ul></div>';
		}

		$html = '<ul class="agml-switcher agml-list">';
		foreach ( $links as $code => $link ) {
			if ( '1' === (string) $atts['hide_current'] && $link['active'] ) {
				continue;
			}
			$html .= $this->list_item( $link, $flags, $atts['names'] );
		}
		return $html . '</ul>';
	}

	private function list_item( array $link, $flags, $names ) {
		return sprintf(
			'<li class="agml-item agml-lang-%1$s%2$s"><a href="%3$s" hreflang="%4$s" lang="%4$s">%5$s</a></li>',
			esc_attr( $link['code'] ),
			$link['active'] ? ' agml-current' : '',
			esc_url( $link['url'] ),
			esc_attr( $link['tag'] ),
			$this->label( $link, $flags, $names )
		);
	}

	public function shortcode( $atts ) {
		return $this->render( (array) $atts );
	}

	public function hreflang() {
		if ( ! $this->plugin->settings->get( 'hreflang' ) || is_404() || is_search() ) {
			return;
		}

		$links = $this->get_links( true );
		if ( count( $links ) < 2 ) {
			return;
		}

		foreach ( $links as $link ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( $link['tag'] ), esc_url( $link['url'] ) );
		}
		$default = $this->plugin->default_language();
		if ( isset( $links[ $default ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $links[ $default ]['url'] ) );
		}
	}
}
