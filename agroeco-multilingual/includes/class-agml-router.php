<?php
/**
 * Визначення поточної мови та побудова мовних URL.
 *
 * Підтримує ті самі формати, що й WPML:
 *  - каталог:  https://site/en/page/
 *  - параметр: https://site/page/?lang=en
 *  - домен:    https://en.site/page/
 */

defined( 'ABSPATH' ) || exit;

class AGML_Router {

	/** @var AGML_Plugin */
	private $plugin;

	/** @var string */
	private $current;

	/** @var string|null Оригінальний REQUEST_URI на час розбору запиту. */
	private $original_request_uri;

	/** @var string[] Шляхи, до яких не додається мовний префікс. */
	private $excluded_paths = array( 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'xmlrpc.php', 'wp-cron.php' );

	public function __construct( AGML_Plugin $plugin ) {
		$this->plugin  = $plugin;
		$this->current = $this->detect_language();

		add_filter( 'do_parse_request', array( $this, 'strip_language_from_request' ), 1 );
		add_action( 'parse_request', array( $this, 'restore_request_uri' ), 1 );
		add_filter( 'locale', array( $this, 'filter_locale' ) );

		if ( ! is_admin() || wp_doing_ajax() ) {
			add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
		}

		add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 20, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 20, 3 );
	}

	/* ------------------------------------------------------------------
	 * Поточна мова
	 * ------------------------------------------------------------------ */

	public function current_language() {
		return $this->current;
	}

	public function set_current_language( $lang ) {
		if ( $this->plugin->is_active_language( $lang ) ) {
			$this->current = $lang;
		}
	}

	private function detect_language() {
		$default = $this->plugin->default_language();

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $this->plugin->admin_language_for_content();
		}

		// Явний параметр має пріоритет (AJAX, форми, формат «параметр»).
		if ( isset( $_GET['lang'] ) && $this->plugin->is_active_language( sanitize_key( wp_unslash( $_GET['lang'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$url     = $this->current_request_url();
		$referer = ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		// AJAX- та REST-запити з адмінки (редактор блоків тощо) — мовою адмінки.
		if ( $referer && ( wp_doing_ajax() || $this->is_rest_request() ) && false !== strpos( $referer, '/wp-admin/' ) ) {
			parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $args );
			return $this->plugin->admin_language_for_content( $args );
		}

		// AJAX-запити з сайту виконуються мовою сторінки, з якої їх надіслано.
		if ( wp_doing_ajax() && $referer ) {
			$url = $referer;
		}

		$lang = $this->language_from_url( $url );
		return $lang ? $lang : $default;
	}

	private function is_rest_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' ) || isset( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	private function current_request_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return $scheme . $host . $uri;
	}

	/**
	 * Мова, закодована в URL, або null.
	 */
	public function language_from_url( $url ) {
		$format = $this->plugin->settings->get( 'url_format' );
		$parts  = wp_parse_url( $url );

		if ( AGML_Settings::URL_PARAMETER === $format ) {
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
				if ( ! empty( $query['lang'] ) && $this->plugin->is_active_language( $query['lang'] ) ) {
					return $query['lang'];
				}
			}
			return null;
		}

		if ( AGML_Settings::URL_DOMAIN === $format ) {
			$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			foreach ( (array) $this->plugin->settings->get( 'domains' ) as $code => $domain ) {
				$domain_host = wp_parse_url( ( false === strpos( $domain, '//' ) ? 'http://' : '' ) . $domain, PHP_URL_HOST );
				if ( $domain_host && strtolower( $domain_host ) === $host && $this->plugin->is_active_language( $code ) ) {
					return $code;
				}
			}
			return null;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$path = $this->strip_home_path( $path );
		$segs = explode( '/', ltrim( $path, '/' ), 2 );

		if ( '' !== $segs[0] && $this->plugin->is_active_language( $segs[0] ) ) {
			return $segs[0];
		}
		return null;
	}

	private function home_path() {
		$path = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		return $path ? '/' . trim( $path, '/' ) : '';
	}

	private function strip_home_path( $path ) {
		$home = $this->home_path();
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return '' === $path ? '/' : $path;
	}

	/* ------------------------------------------------------------------
	 * Розбір запиту: прибираємо /en/ перед тим, як WordPress розбере URL
	 * ------------------------------------------------------------------ */

	public function strip_language_from_request( $do_parse ) {
		if ( AGML_Settings::URL_DIRECTORY !== $this->plugin->settings->get( 'url_format' ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return $do_parse;
		}

		$uri  = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = $this->home_path();
		$rest = ( '' !== $home && 0 === strpos( $uri, $home ) ) ? substr( $uri, strlen( $home ) ) : $uri;

		if ( preg_match( '#^/([a-zA-Z_-]{2,10})(/|\?|$)(.*)$#s', $rest, $m ) && $this->plugin->is_active_language( $m[1] ) ) {
			$this->original_request_uri = $uri;
			$tail                       = ( '?' === $m[2] ? '/?' : '/' ) . $m[3];
			$_SERVER['REQUEST_URI']     = $home . $tail;
		}

		return $do_parse;
	}

	public function restore_request_uri() {
		if ( null !== $this->original_request_uri ) {
			$_SERVER['REQUEST_URI']     = $this->original_request_uri;
			$this->original_request_uri = null;
		}
	}

	public function filter_locale( $locale ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $locale;
		}
		$languages = $this->plugin->db->get_all_languages();
		return isset( $languages[ $this->current ] ) ? $languages[ $this->current ]['locale'] : $locale;
	}

	/* ------------------------------------------------------------------
	 * Побудова URL
	 * ------------------------------------------------------------------ */

	/**
	 * Перетворює внутрішній URL сайту на URL мовою $lang.
	 */
	public function convert_url( $url, $lang ) {
		if ( ! $lang || ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || ! $this->is_internal_host( isset( $parts['host'] ) ? $parts['host'] : '' ) ) {
			return $url;
		}

		$default = $this->plugin->default_language();
		$format  = $this->plugin->settings->get( 'url_format' );
		$path    = isset( $parts['path'] ) ? $parts['path'] : '';
		$query   = isset( $parts['query'] ) ? $parts['query'] : '';

		if ( AGML_Settings::URL_PARAMETER === $format ) {
			// user_trailingslashit() для URL з ?lang=xx дописує «/» у кінець query-рядка.
			if ( '/' === substr( $query, -1 ) ) {
				$query = rtrim( $query, '/' );
				$path  = '/' === substr( $path, -1 ) ? $path : $path . '/';
			}
			parse_str( $query, $args );
			unset( $args['lang'] );
			if ( $lang !== $default ) {
				$args['lang'] = $lang;
			}
			$query = http_build_query( $args );
		} elseif ( AGML_Settings::URL_DOMAIN === $format ) {
			$target = $this->domain_for( $lang );
			if ( $target ) {
				$parts['host'] = $target['host'];
				if ( isset( $target['scheme'] ) ) {
					$parts['scheme'] = $target['scheme'];
				}
				unset( $parts['port'] );
				if ( isset( $target['port'] ) ) {
					$parts['port'] = $target['port'];
				}
			}
		} else {
			$home = $this->home_path();
			$rest = ( '' !== $home && 0 === strpos( $path, $home ) ) ? substr( $path, strlen( $home ) ) : $path;
			$rest = '/' . ltrim( $rest, '/' );

			if ( preg_match( '#^/([a-zA-Z_-]{2,10})(/|$)#', $rest, $m ) && $this->plugin->is_known_language( $m[1] ) ) {
				$rest = '/' . substr( $rest, strlen( $m[0] ) );
			}

			if ( $lang !== $default || $this->plugin->settings->get( 'default_in_directory' ) ) {
				$rest = '/' . $lang . ( '/' === $rest ? '/' : $rest );
			}

			// Не додаємо кінцеву скісну риску, якщо її не було у вихідному URL.
			if ( '' === $path && '/' === $rest ) {
				$rest = '';
			}
			$path = $home . $rest;
		}

		return $this->build_url( $parts, $path, $query );
	}

	private function build_url( array $parts, $path, $query ) {
		$url = '';
		if ( isset( $parts['scheme'] ) ) {
			$url .= $parts['scheme'] . ':';
		}
		if ( isset( $parts['host'] ) ) {
			$url .= '//' . $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$url .= ':' . $parts['port'];
			}
		}
		$url .= $path;
		if ( '' !== $query ) {
			$url .= '?' . $query;
		}
		if ( isset( $parts['fragment'] ) ) {
			$url .= '#' . $parts['fragment'];
		}
		return $url;
	}

	private function domain_for( $lang ) {
		$domains = (array) $this->plugin->settings->get( 'domains' );
		if ( $lang === $this->plugin->default_language() || empty( $domains[ $lang ] ) ) {
			return wp_parse_url( get_option( 'home' ) );
		}
		$domain = $domains[ $lang ];
		return wp_parse_url( ( false === strpos( $domain, '//' ) ? 'http://' : '' ) . $domain );
	}

	private function is_internal_host( $host ) {
		if ( '' === $host ) {
			return true; // Відносний URL.
		}
		$host  = strtolower( $host );
		$hosts = array( strtolower( (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) ) );
		foreach ( (array) $this->plugin->settings->get( 'domains' ) as $domain ) {
			$hosts[] = strtolower( (string) wp_parse_url( ( false === strpos( $domain, '//' ) ? 'http://' : '' ) . $domain, PHP_URL_HOST ) );
		}
		return in_array( $host, $hosts, true );
	}

	/* ------------------------------------------------------------------
	 * Фільтри посилань
	 * ------------------------------------------------------------------ */

	public function filter_home_url( $url, $path ) {
		$path = ltrim( (string) $path, '/' );
		foreach ( $this->excluded_paths as $excluded ) {
			if ( 0 === strpos( $path, $excluded ) ) {
				return $url;
			}
		}
		return $this->convert_url( $url, $this->current );
	}

	public function filter_post_link( $url, $post ) {
		$post = get_post( $post );
		if ( ! $post || ! $this->plugin->is_translatable_post_type( $post->post_type ) ) {
			return $url;
		}
		$lang = $this->plugin->db->get_element_language( $post->ID, 'post_' . $post->post_type );
		return $this->convert_url( $url, $lang ? $lang : $this->plugin->default_language() );
	}

	public function filter_page_link( $url, $post_id ) {
		return $this->filter_post_link( $url, $post_id );
	}

	public function filter_term_link( $url, $term, $taxonomy ) {
		if ( ! $this->plugin->is_translatable_taxonomy( $taxonomy ) ) {
			return $url;
		}
		$lang = $this->plugin->db->get_element_language( $term->term_taxonomy_id, 'tax_' . $taxonomy );
		return $this->convert_url( $url, $lang ? $lang : $this->plugin->default_language() );
	}
}
