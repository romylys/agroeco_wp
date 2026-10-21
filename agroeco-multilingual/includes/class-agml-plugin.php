<?php
/**
 * Ядро плагіна: створює модулі та надає спільні допоміжні методи.
 */

defined( 'ABSPATH' ) || exit;

class AGML_Plugin {

	/** @var AGML_Plugin|null */
	private static $instance;

	/** @var AGML_DB */
	public $db;

	/** @var AGML_Settings */
	public $settings;

	/** @var AGML_Router */
	public $router;

	/** @var AGML_Query */
	public $query;

	/** @var AGML_Strings */
	public $strings;

	/** @var AGML_Switcher */
	public $switcher;

	/** @var AGML_Admin */
	public $admin;

	public static function instance() {
		return self::$instance;
	}

	public static function boot() {
		if ( self::$instance ) {
			return;
		}

		// Одночасна робота з WPML неможлива: обидва плагіни фільтрують ті самі запити.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'wpml_active_notice' ) );
			return;
		}

		self::$instance = new self();
		self::$instance->init();
	}

	public static function wpml_active_notice() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Agroeco Multilingual не працює, поки активний WPML. Деактивуйте (але не видаляйте) WPML Multilingual CMS та WPML Flag in Menu Extended — усі переклади збережуться і будуть використані автоматично.', 'agroeco-multilingual' );
		echo '</p></div>';
	}

	private function init() {
		$this->db       = new AGML_DB();
		$this->settings = new AGML_Settings();

		if ( ! $this->db->tables_exist() ) {
			AGML_Install::create_tables();
		}

		$this->router   = new AGML_Router( $this );
		$this->query    = new AGML_Query( $this );
		$this->strings  = new AGML_Strings( $this );
		$this->switcher = new AGML_Switcher( $this );

		// Не лише в адмінці: редактор блоків зберігає записи через REST API.
		$this->admin = new AGML_Admin( $this );

		require_once AGML_DIR . 'includes/compat-wpml.php';

		do_action( 'agml_loaded', $this );
	}

	/* ------------------------------------------------------------------
	 * Мови
	 * ------------------------------------------------------------------ */

	public function default_language() {
		return (string) $this->settings->get( 'default_language' );
	}

	/**
	 * Активні мови; мова за замовчуванням завжди перша.
	 *
	 * @return array<string,array>
	 */
	public function active_languages() {
		$default = $this->default_language();
		$result  = array();

		foreach ( $this->db->get_all_languages() as $code => $language ) {
			if ( $language['active'] || $code === $default ) {
				$result[ $code ] = $language;
			}
		}

		if ( isset( $result[ $default ] ) ) {
			$result = array( $default => $result[ $default ] ) + $result;
		}

		return apply_filters( 'agml_active_languages', $result );
	}

	public function is_active_language( $code ) {
		if ( ! is_string( $code ) || '' === $code ) {
			return false;
		}
		$languages = $this->active_languages();
		return isset( $languages[ $code ] );
	}

	public function is_known_language( $code ) {
		$languages = $this->db->get_all_languages();
		return isset( $languages[ $code ] );
	}

	/**
	 * Поточна мова: на сайті — мова сторінки, в адмінці — вибрана мова контенту (може бути 'all').
	 */
	public function current_language() {
		return $this->router ? $this->router->current_language() : $this->default_language();
	}

	/**
	 * Поточна мова, придатна для створення контенту (ніколи не 'all').
	 */
	public function content_language() {
		$lang = $this->current_language();
		return 'all' === $lang || ! $this->is_active_language( $lang ) ? $this->default_language() : $lang;
	}

	/**
	 * Мова контенту в адмінці. Під час редагування запису — мова цього запису.
	 *
	 * @param array|null $args Параметри запиту адмінки (за замовчуванням $_GET).
	 */
	public function admin_language_for_content( $args = null ) {
		$args = null === $args ? $_GET : $args; // phpcs:ignore WordPress.Security.NonceVerification

		if ( isset( $args['post'] ) && is_numeric( $args['post'] ) ) {
			$post = get_post( (int) $args['post'] );
			if ( $post && $this->is_translatable_post_type( $post->post_type ) ) {
				$lang = $this->db->get_element_language( $post->ID, 'post_' . $post->post_type );
				return $lang ? $lang : $this->default_language();
			}
		}
		if ( isset( $args['agml_lang'] ) && $this->is_active_language( sanitize_key( $args['agml_lang'] ) ) ) {
			return sanitize_key( $args['agml_lang'] );
		}

		return $this->admin_language();
	}

	/**
	 * Мова фільтра в адмінці, збережена для користувача ('all' або код мови).
	 */
	public function admin_language() {
		$lang = get_user_meta( get_current_user_id(), 'agml_admin_lang', true );
		if ( 'all' === $lang || $this->is_active_language( $lang ) ) {
			return $lang;
		}
		return $this->default_language();
	}

	public function set_admin_language( $lang ) {
		if ( 'all' === $lang || $this->is_active_language( $lang ) ) {
			update_user_meta( get_current_user_id(), 'agml_admin_lang', $lang );
		}
	}

	/* ------------------------------------------------------------------
	 * Типи контенту
	 * ------------------------------------------------------------------ */

	public function is_translatable_post_type( $post_type ) {
		return in_array( $post_type, $this->settings->post_types(), true );
	}

	public function is_translatable_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, $this->settings->taxonomies(), true );
	}

	/**
	 * Мова запису, що зараз зберігається (з форми, з БД або поточна).
	 */
	public function language_for_saved_post( $post_id, $post_type ) {
		if ( isset( $_POST['agml_language'], $_POST['agml_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['agml_nonce'] ), 'agml_save_post' ) ) {
			$lang = sanitize_key( wp_unslash( $_POST['agml_language'] ) );
			if ( $this->is_active_language( $lang ) ) {
				return $lang;
			}
		}
		if ( $post_id ) {
			$lang = $this->db->get_element_language( $post_id, 'post_' . $post_type );
			if ( $lang ) {
				return $lang;
			}
		}
		return $this->content_language();
	}

	/**
	 * Мова довільного запису (з урахуванням записів без мовної прив'язки).
	 */
	public function get_post_language( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		if ( ! $this->is_translatable_post_type( $post->post_type ) ) {
			return null;
		}
		$lang = $this->db->get_element_language( $post->ID, 'post_' . $post->post_type );
		return $lang ? $lang : $this->default_language();
	}

	/**
	 * ID перекладу запису/терміна (аналог фільтра wpml_object_id).
	 *
	 * @param int    $id
	 * @param string $type          Тип запису або таксономія.
	 * @param bool   $return_original Повертати оригінал, якщо перекладу немає.
	 * @param string $lang
	 * @return int|null
	 */
	public function object_id( $id, $type = 'post', $return_original = false, $lang = null ) {
		$lang = $lang ? $lang : $this->content_language();
		$id   = (int) $id;
		if ( ! $id ) {
			return null;
		}

		if ( 'any' === $type ) {
			$post = get_post( $id );
			$type = $post ? $post->post_type : 'post';
		}

		if ( taxonomy_exists( $type ) || 'nav_menu' === $type ) {
			if ( ! $this->is_translatable_taxonomy( $type ) ) {
				return $id;
			}
			$term = get_term( $id, $type );
			if ( ! $term || is_wp_error( $term ) ) {
				return $return_original ? $id : null;
			}
			$tt_id = $this->db->get_translated_id( $term->term_taxonomy_id, 'tax_' . $type, $lang );
			if ( $tt_id ) {
				global $wpdb;
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) );
			}
			return $return_original ? $id : null;
		}

		if ( ! $this->is_translatable_post_type( $type ) ) {
			return $id;
		}
		$translated = $this->db->get_translated_id( $id, 'post_' . $type, $lang );
		if ( $translated ) {
			return $translated;
		}
		if ( ! $this->db->get_element( $id, 'post_' . $type ) && $lang === $this->default_language() ) {
			return $id; // Запис без мовної прив'язки вважається записом мовою за замовчуванням.
		}
		return $return_original ? $id : null;
	}
}
