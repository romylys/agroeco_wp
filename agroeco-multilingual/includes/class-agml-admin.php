<?php
/**
 * Адмінка: вибір мови контенту, колонки перекладів, метабокс мови,
 * створення перекладів, мови термінів і меню, сторінка налаштувань.
 */

defined( 'ABSPATH' ) || exit;

class AGML_Admin {

	/** @var AGML_Plugin */
	private $plugin;

	/** Мета-поля, які не копіюються під час створення перекладу. */
	const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	public function __construct( AGML_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_init', array( $this, 'handle_admin_language_switch' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );

		// Записи.
		add_action( 'current_screen', array( $this, 'register_list_columns' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'delete_post', array( $this, 'delete_post' ) );
		add_action( 'admin_post_agml_translate', array( $this, 'create_translation' ) );

		// Терміни та меню.
		add_action( 'init', array( $this, 'register_term_hooks' ), 100 );
		add_action( 'created_term', array( $this, 'save_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'delete_term' ), 10, 3 );
		add_action( 'wp_create_nav_menu', array( $this, 'create_nav_menu' ) );
		add_action( 'admin_head-nav-menus.php', array( $this, 'add_nav_menu_box' ) );
		add_action( 'admin_post_agml_menu_language', array( $this, 'save_menu_language' ) );

		// Налаштування.
		add_action( 'admin_post_agml_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_agml_add_language', array( $this, 'add_language' ) );
	}

	public function enqueue() {
		wp_enqueue_style( 'agml-admin', AGML_URL . 'assets/admin.css', array(), AGML_VERSION );
	}

	/* ------------------------------------------------------------------
	 * Мова контенту в адмінці
	 * ------------------------------------------------------------------ */

	public function handle_admin_language_switch() {
		if ( ! isset( $_GET['agml_admin_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! isset( $_GET['_agmlnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_agmlnonce'] ), 'agml_admin_lang' ) ) {
			wp_safe_redirect( remove_query_arg( array( 'agml_admin_lang', '_agmlnonce' ) ) );
			exit;
		}
		$this->plugin->set_admin_language( sanitize_key( wp_unslash( $_GET['agml_admin_lang'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( remove_query_arg( array( 'agml_admin_lang', 'agml_lang', '_agmlnonce' ) ) );
		exit;
	}

	public function admin_bar( WP_Admin_Bar $bar ) {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$current   = $this->plugin->current_language();
		$languages = $this->plugin->active_languages();
		$title     = 'all' === $current ? __( 'Усі мови', 'agroeco-multilingual' ) : ( isset( $languages[ $current ] ) ? $languages[ $current ]['native_name'] : $current );

		$bar->add_node(
			array(
				'id'    => 'agml-language',
				'title' => $this->flag_html( $current ) . esc_html( $title ),
				'href'  => '#',
			)
		);

		foreach ( $languages as $code => $language ) {
			$bar->add_node(
				array(
					'parent' => 'agml-language',
					'id'     => 'agml-language-' . $code,
					'title'  => $this->flag_html( $code ) . esc_html( $language['native_name'] ),
					'href'   => $this->admin_lang_url( $code ),
				)
			);
		}
		$bar->add_node(
			array(
				'parent' => 'agml-language',
				'id'     => 'agml-language-all',
				'title'  => esc_html__( 'Усі мови', 'agroeco-multilingual' ),
				'href'   => $this->admin_lang_url( 'all' ),
			)
		);
	}

	private function admin_lang_url( $lang ) {
		return add_query_arg(
			array(
				'agml_admin_lang' => $lang,
				'_agmlnonce'      => wp_create_nonce( 'agml_admin_lang' ),
			)
		);
	}

	private function flag_html( $code ) {
		$url = 'all' === $code ? '' : $this->plugin->db->get_flag_url( $code );
		return $url ? '<img class="agml-flag" src="' . esc_url( $url ) . '" alt="" width="18" height="12" /> ' : '';
	}

	public function notices() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['agml_notice'] ) && 'saved' === $_GET['agml_notice'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Налаштування збережено.', 'agroeco-multilingual' ) . '</p></div>';
		}
		if ( isset( $_GET['agml_notice'] ) && 'translation_created' === $_GET['agml_notice'] ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Створено чернетку перекладу з копією вмісту оригіналу. Перекладіть текст і опублікуйте.', 'agroeco-multilingual' ) . '</p></div>';
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------------------
	 * Списки записів
	 * ------------------------------------------------------------------ */

	public function register_list_columns( $screen ) {
		if ( 'edit' !== $screen->base || ! $this->plugin->is_translatable_post_type( $screen->post_type ) ) {
			return;
		}

		add_filter( "manage_{$screen->post_type}_posts_columns", array( $this, 'add_column' ) );
		add_action( "manage_{$screen->post_type}_posts_custom_column", array( $this, 'render_post_column' ), 10, 2 );
		add_filter( "views_edit-{$screen->post_type}", array( $this, 'language_views' ) );
	}

	public function add_column( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key || 'name' === $key ) {
				$result['agml_languages'] = __( 'Мови', 'agroeco-multilingual' );
			}
		}
		if ( ! isset( $result['agml_languages'] ) ) {
			$result['agml_languages'] = __( 'Мови', 'agroeco-multilingual' );
		}
		return $result;
	}

	public function render_post_column( $column, $post_id ) {
		if ( 'agml_languages' !== $column ) {
			return;
		}
		$post = get_post( $post_id );
		echo $this->translation_links_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Прапори з посиланнями: редагувати переклад або створити новий.
	 */
	private function translation_links_html( WP_Post $post ) {
		$lang         = $this->plugin->get_post_language( $post );
		$translations = $this->plugin->db->get_element_translations( $post->ID, 'post_' . $post->post_type );
		$html         = '<span class="agml-translations">';

		foreach ( $this->plugin->active_languages() as $code => $language ) {
			$label = $this->flag_html( $code );
			$label = $label ? $label : '<span class="agml-code">' . esc_html( strtoupper( $code ) ) . '</span>';

			if ( $code === $lang ) {
				$html .= '<span class="agml-tr agml-tr-current" title="' . esc_attr( $language['native_name'] ) . '">' . $label . '</span>';
			} elseif ( isset( $translations[ $code ] ) ) {
				$html .= sprintf(
					'<a class="agml-tr agml-tr-exists" href="%s" title="%s">%s</a>',
					esc_url( get_edit_post_link( (int) $translations[ $code ]['element_id'] ) ),
					/* translators: %s: language name */
					esc_attr( sprintf( __( 'Редагувати переклад: %s', 'agroeco-multilingual' ), $language['native_name'] ) ),
					$label
				);
			} else {
				$html .= sprintf(
					'<a class="agml-tr agml-tr-missing" href="%s" title="%s">%s<span class="agml-plus">+</span></a>',
					esc_url( $this->translate_url( $post->ID, $code ) ),
					/* translators: %s: language name */
					esc_attr( sprintf( __( 'Створити переклад: %s', 'agroeco-multilingual' ), $language['native_name'] ) ),
					$label
				);
			}
		}

		return $html . '</span>';
	}

	private function translate_url( $post_id, $lang ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=agml_translate&post=' . (int) $post_id . '&lang=' . rawurlencode( $lang ) ),
			'agml_translate_' . $post_id
		);
	}

	public function language_views( $views ) {
		$current = $this->plugin->current_language();
		foreach ( $this->plugin->active_languages() as $code => $language ) {
			$views[ 'agml-' . $code ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $this->admin_lang_url( $code ) ),
				$code === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $language['native_name'] )
			);
		}
		$views['agml-all'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $this->admin_lang_url( 'all' ) ),
			'all' === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Усі мови', 'agroeco-multilingual' )
		);
		return $views;
	}

	/* ------------------------------------------------------------------
	 * Метабокс мови на екрані редагування
	 * ------------------------------------------------------------------ */

	public function add_meta_boxes( $post_type, $post ) {
		if ( ! $this->plugin->is_translatable_post_type( $post_type ) ) {
			return;
		}
		add_meta_box( 'agml-language', __( 'Мова', 'agroeco-multilingual' ), array( $this, 'render_meta_box' ), $post_type, 'side', 'high' );
	}

	public function render_meta_box( WP_Post $post ) {
		$row  = $this->plugin->db->get_element( $post->ID, 'post_' . $post->post_type );
		$lang = $row ? $row['language_code'] : $this->plugin->content_language();

		wp_nonce_field( 'agml_save_post', 'agml_nonce' );

		echo '<p><label for="agml_language">' . esc_html__( 'Мова запису', 'agroeco-multilingual' ) . '</label><br />';
		echo '<select name="agml_language" id="agml_language" class="widefat">';
		foreach ( $this->plugin->active_languages() as $code => $language ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $code, $lang, false ), esc_html( $language['native_name'] ) );
		}
		echo '</select></p>';

		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Щоб створити переклад наявного запису, натисніть «+» біля нього у списку записів.', 'agroeco-multilingual' ) . '</p>';
			return;
		}

		$translations = $row ? $this->plugin->db->get_translations_by_trid( $row['trid'] ) : array();

		echo '<p><strong>' . esc_html__( 'Переклади', 'agroeco-multilingual' ) . '</strong></p><ul class="agml-meta-translations">';
		foreach ( $this->plugin->active_languages() as $code => $language ) {
			if ( $code === $lang ) {
				continue;
			}
			echo '<li>' . $this->flag_html( $code ) . esc_html( $language['native_name'] ) . ': '; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( isset( $translations[ $code ] ) ) {
				$tr_id = (int) $translations[ $code ]['element_id'];
				printf(
					'<a href="%s">%s</a> <span class="agml-status">(%s)</span>',
					esc_url( get_edit_post_link( $tr_id ) ),
					esc_html( get_the_title( $tr_id ) ? get_the_title( $tr_id ) : '#' . $tr_id ),
					esc_html( get_post_status( $tr_id ) )
				);
			} else {
				printf( '<a class="button button-small" href="%s">%s</a>', esc_url( $this->translate_url( $post->ID, $code ) ), esc_html__( '+ Створити', 'agroeco-multilingual' ) );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	public function save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! $this->plugin->is_translatable_post_type( $post->post_type ) ) {
			return;
		}

		$type     = 'post_' . $post->post_type;
		$existing = $this->plugin->db->get_element( $post_id, $type );

		if ( isset( $_POST['agml_nonce'], $_POST['agml_language'] ) && wp_verify_nonce( sanitize_key( $_POST['agml_nonce'] ), 'agml_save_post' ) ) {
			$lang = sanitize_key( wp_unslash( $_POST['agml_language'] ) );
			if ( ! $this->plugin->is_active_language( $lang ) ) {
				return;
			}
			if ( ! $existing || $existing['language_code'] !== $lang ) {
				$this->plugin->db->set_element_language(
					$post_id,
					$type,
					$lang,
					$existing ? (int) $existing['trid'] : null,
					$existing ? $existing['source_language_code'] : null
				);
			}
			return;
		}

		if ( ! $existing ) {
			$this->plugin->db->set_element_language( $post_id, $type, $this->plugin->content_language() );
		}
	}

	public function delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && $this->plugin->is_translatable_post_type( $post->post_type ) ) {
			$this->plugin->db->delete_element( $post_id, 'post_' . $post->post_type );
		}
	}

	/**
	 * Створює чернетку перекладу як копію оригіналу (вміст, мета-поля, рубрики).
	 */
	public function create_translation() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$lang    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'agml_translate_' . $post_id );

		$original  = get_post( $post_id );
		$type_obj  = $original ? get_post_type_object( $original->post_type ) : null;
		$can_write = $type_obj && current_user_can( 'edit_post', $post_id ) && current_user_can( $type_obj->cap->create_posts );

		if ( ! $can_write || ! $this->plugin->is_translatable_post_type( $original->post_type ) || ! $this->plugin->is_active_language( $lang )
			|| $lang === $this->plugin->get_post_language( $original ) ) {
			wp_die( esc_html__( 'Неможливо створити переклад.', 'agroeco-multilingual' ), 403 );
		}

		$type = 'post_' . $original->post_type;
		$db   = $this->plugin->db;

		$row = $db->get_element( $original->ID, $type );
		if ( ! $row ) {
			$db->set_element_language( $original->ID, $type, $this->plugin->default_language() );
			$row = $db->get_element( $original->ID, $type );
		}

		$translations = $db->get_translations_by_trid( $row['trid'] );
		if ( isset( $translations[ $lang ] ) ) {
			wp_safe_redirect( get_edit_post_link( (int) $translations[ $lang ]['element_id'], 'url' ) );
			exit;
		}

		$parent = 0;
		if ( $original->post_parent ) {
			$parent = (int) $db->get_translated_id( $original->post_parent, $type, $lang );
		}

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $original->post_type,
					'post_status'    => 'draft',
					'post_title'     => $original->post_title,
					'post_content'   => $original->post_content,
					'post_excerpt'   => $original->post_excerpt,
					'post_author'    => get_current_user_id(),
					'post_parent'    => $parent,
					'menu_order'     => $original->menu_order,
					'comment_status' => $original->comment_status,
					'ping_status'    => $original->ping_status,
					'post_password'  => $original->post_password,
				)
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		$db->set_element_language( $new_id, $type, $lang, (int) $row['trid'], $row['language_code'] );

		// Мета-поля (зокрема мініатюра, поля ACF, налаштування конструкторів сторінок).
		foreach ( get_post_meta( $original->ID ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) || 0 === strpos( $key, '_wpml_' ) || 0 === strpos( $key, '_icl_' ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}

		// Терміни: для перекладних таксономій — відповідні переклади термінів.
		foreach ( get_object_taxonomies( $original->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $original->ID, $taxonomy, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$ids = array();
			foreach ( $terms as $term ) {
				if ( $this->plugin->is_translatable_taxonomy( $taxonomy ) ) {
					$translated = $this->plugin->object_id( $term->term_id, $taxonomy, false, $lang );
					if ( $translated ) {
						$ids[] = (int) $translated;
					}
				} else {
					$ids[] = (int) $term->term_id;
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $new_id, $ids, $taxonomy );
			}
		}

		do_action( 'agml_translation_created', $new_id, $original->ID, $lang );

		wp_safe_redirect( add_query_arg( 'agml_notice', 'translation_created', get_edit_post_link( $new_id, 'url' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Терміни
	 * ------------------------------------------------------------------ */

	public function register_term_hooks() {
		foreach ( $this->plugin->settings->taxonomies() as $taxonomy ) {
			if ( 'nav_menu' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'term_add_fields' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'term_edit_fields' ), 10, 2 );
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_column' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_term_column' ), 10, 3 );
		}
	}

	private function language_select( $selected ) {
		$html = '<select name="agml_language" id="agml_language">';
		foreach ( $this->plugin->active_languages() as $code => $language ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $code, $selected, false ), esc_html( $language['native_name'] ) );
		}
		return $html . '</select>';
	}

	public function term_add_fields( $taxonomy ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$lang = isset( $_GET['agml_lang'] ) ? sanitize_key( $_GET['agml_lang'] ) : $this->plugin->content_language();
		$trid = isset( $_GET['agml_trid'] ) ? (int) $_GET['agml_trid'] : 0;
		// phpcs:enable

		wp_nonce_field( 'agml_save_term', 'agml_term_nonce' );
		echo '<div class="form-field"><label for="agml_language">' . esc_html__( 'Мова', 'agroeco-multilingual' ) . '</label>';
		echo $this->language_select( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $trid ) {
			echo '<input type="hidden" name="agml_trid" value="' . esc_attr( $trid ) . '" />';
			echo '<p>' . esc_html__( 'Термін буде збережено як переклад.', 'agroeco-multilingual' ) . '</p>';
		}
		echo '</div>';
	}

	public function term_edit_fields( $term, $taxonomy ) {
		$row  = $this->plugin->db->get_element( $term->term_taxonomy_id, 'tax_' . $taxonomy );
		$lang = $row ? $row['language_code'] : $this->plugin->default_language();

		wp_nonce_field( 'agml_save_term', 'agml_term_nonce' );
		echo '<tr class="form-field"><th scope="row"><label for="agml_language">' . esc_html__( 'Мова', 'agroeco-multilingual' ) . '</label></th><td>';
		echo $this->language_select( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</td></tr>';

		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Переклади', 'agroeco-multilingual' ) . '</th><td>';
		echo $this->term_translation_links( $term, $taxonomy ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</td></tr>';
	}

	public function render_term_column( $content, $column, $term_id ) {
		if ( 'agml_languages' !== $column ) {
			return $content;
		}
		$screen = get_current_screen();
		$term   = get_term( $term_id, $screen ? $screen->taxonomy : '' );
		return $term && ! is_wp_error( $term ) ? $this->term_translation_links( $term, $term->taxonomy ) : $content;
	}

	private function term_translation_links( WP_Term $term, $taxonomy ) {
		$type = 'tax_' . $taxonomy;
		$row  = $this->plugin->db->get_element( $term->term_taxonomy_id, $type );
		$lang = $row ? $row['language_code'] : $this->plugin->default_language();
		$trs  = $row ? $this->plugin->db->get_translations_by_trid( $row['trid'] ) : array();
		$html = '<span class="agml-translations">';

		foreach ( $this->plugin->active_languages() as $code => $language ) {
			$label = $this->flag_html( $code );
			$label = $label ? $label : '<span class="agml-code">' . esc_html( strtoupper( $code ) ) . '</span>';

			if ( $code === $lang ) {
				$html .= '<span class="agml-tr agml-tr-current" title="' . esc_attr( $language['native_name'] ) . '">' . $label . '</span>';
			} elseif ( isset( $trs[ $code ] ) ) {
				$tr_term = $this->plugin->object_id( $term->term_id, $taxonomy, false, $code );
				$html   .= sprintf( '<a class="agml-tr agml-tr-exists" href="%s" title="%s">%s</a>', esc_url( get_edit_term_link( $tr_term, $taxonomy ) ), esc_attr( $language['native_name'] ), $label );
			} else {
				$trid = $row ? (int) $row['trid'] : 0;
				$url  = admin_url( 'edit-tags.php?taxonomy=' . $taxonomy . '&agml_lang=' . $code . ( $trid ? '&agml_trid=' . $trid : '' ) );
				if ( ! $trid ) {
					// Оригінал ще не має мовного запису — створюємо його, щоб переклад мав групу.
					$trid = $this->plugin->db->set_element_language( $term->term_taxonomy_id, $type, $lang );
					$url  = add_query_arg( 'agml_trid', $trid, $url );
				}
				$html .= sprintf( '<a class="agml-tr agml-tr-missing" href="%s" title="%s">%s<span class="agml-plus">+</span></a>', esc_url( $url ), esc_attr( $language['native_name'] ), $label );
			}
		}
		return $html . '</span>';
	}

	public function save_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! $this->plugin->is_translatable_taxonomy( $taxonomy ) || 'nav_menu' === $taxonomy ) {
			return;
		}

		$type     = 'tax_' . $taxonomy;
		$existing = $this->plugin->db->get_element( $tt_id, $type );

		if ( isset( $_POST['agml_term_nonce'], $_POST['agml_language'] ) && wp_verify_nonce( sanitize_key( $_POST['agml_term_nonce'] ), 'agml_save_term' ) ) {
			$lang = sanitize_key( wp_unslash( $_POST['agml_language'] ) );
			$trid = isset( $_POST['agml_trid'] ) ? (int) $_POST['agml_trid'] : 0;
			if ( ! $this->plugin->is_active_language( $lang ) ) {
				return;
			}
			if ( $existing && $existing['language_code'] === $lang && ! $trid ) {
				return;
			}

			$source = null;
			if ( $trid ) {
				$group = $this->plugin->db->get_translations_by_trid( $trid );
				foreach ( $group as $code => $member ) {
					if ( empty( $member['source_language_code'] ) ) {
						$source = $code;
					}
				}
			}
			$this->plugin->db->set_element_language( $tt_id, $type, $lang, $trid ? $trid : ( $existing ? (int) $existing['trid'] : null ), $source );
			return;
		}

		if ( ! $existing ) {
			$this->plugin->db->set_element_language( $tt_id, $type, $this->plugin->content_language() );
		}
	}

	public function delete_term( $term_id, $tt_id, $taxonomy ) {
		if ( $this->plugin->is_translatable_taxonomy( $taxonomy ) ) {
			$this->plugin->db->delete_element( $tt_id, 'tax_' . $taxonomy );
		}
	}

	/* ------------------------------------------------------------------
	 * Меню
	 * ------------------------------------------------------------------ */

	public function create_nav_menu( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( $menu && ! $this->plugin->db->get_element( $menu->term_taxonomy_id, 'tax_nav_menu' ) ) {
			$this->plugin->db->set_element_language( $menu->term_taxonomy_id, 'tax_nav_menu', $this->plugin->content_language() );
		}
	}

	public function add_nav_menu_box() {
		add_meta_box( 'agml-nav-menu-language', __( 'Мова меню', 'agroeco-multilingual' ), array( $this, 'render_nav_menu_box' ), 'nav-menus', 'side', 'high' );
	}

	/**
	 * Метабокс на сторінці «Меню»: мова поточного меню та зв'язок із меню іншими мовами.
	 * Метабокси цієї сторінки розташовані всередині форми, тому використовуємо formaction.
	 */
	public function render_nav_menu_box() {
		$menu_id = isset( $_REQUEST['menu'] ) ? (int) $_REQUEST['menu'] : (int) get_user_option( 'nav_menu_recently_edited' ); // phpcs:ignore WordPress.Security.NonceVerification
		$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;

		if ( ! $menu ) {
			echo '<p>' . esc_html__( 'Нове меню отримає поточну мову адмінки.', 'agroeco-multilingual' ) . '</p>';
			return;
		}

		$row  = $this->plugin->db->get_element( $menu->term_taxonomy_id, 'tax_nav_menu' );
		$lang = $row ? $row['language_code'] : $this->plugin->default_language();
		$trs  = $row ? $this->plugin->db->get_translations_by_trid( $row['trid'] ) : array();

		$all_menus = wp_get_nav_menus( array( 'lang' => 'all' ) );

		wp_nonce_field( 'agml_menu_language', 'agml_menu_nonce' );
		echo '<input type="hidden" name="agml_menu_id" value="' . esc_attr( $menu->term_id ) . '" />';
		echo '<p><label>' . esc_html__( 'Мова меню', 'agroeco-multilingual' ) . '<br />' . $this->language_select( $lang ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		foreach ( $this->plugin->active_languages() as $code => $language ) {
			if ( $code === $lang ) {
				continue;
			}
			$linked = isset( $trs[ $code ] ) ? (int) $trs[ $code ]['element_id'] : 0;
			echo '<p><label>' . $this->flag_html( $code ) . esc_html( $language['native_name'] ) . '<br />'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<select name="agml_menu_translation[' . esc_attr( $code ) . ']" class="widefat"><option value="0">—</option>';
			foreach ( $all_menus as $candidate ) {
				if ( $candidate->term_id === $menu->term_id ) {
					continue;
				}
				printf( '<option value="%d"%s>%s</option>', (int) $candidate->term_taxonomy_id, selected( $linked, (int) $candidate->term_taxonomy_id, false ), esc_html( $candidate->name ) );
			}
			echo '</select></label></p>';
		}

		printf(
			'<p><button type="submit" class="button" formaction="%s">%s</button></p>',
			esc_url( admin_url( 'admin-post.php?action=agml_menu_language' ) ),
			esc_html__( 'Зберегти мову меню', 'agroeco-multilingual' )
		);
	}

	public function save_menu_language() {
		check_admin_referer( 'agml_menu_language', 'agml_menu_nonce' );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'agroeco-multilingual' ) );
		}

		$menu = wp_get_nav_menu_object( isset( $_POST['agml_menu_id'] ) ? (int) $_POST['agml_menu_id'] : 0 );
		$lang = isset( $_POST['agml_language'] ) ? sanitize_key( wp_unslash( $_POST['agml_language'] ) ) : '';

		if ( $menu && $this->plugin->is_active_language( $lang ) ) {
			$db   = $this->plugin->db;
			$row  = $db->get_element( $menu->term_taxonomy_id, 'tax_nav_menu' );
			$trid = $db->set_element_language( $menu->term_taxonomy_id, 'tax_nav_menu', $lang, $row ? (int) $row['trid'] : null );

			$links = isset( $_POST['agml_menu_translation'] ) ? (array) wp_unslash( $_POST['agml_menu_translation'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $links as $code => $tt_id ) {
				$code  = sanitize_key( $code );
				$tt_id = (int) $tt_id;
				if ( $code === $lang || ! $this->plugin->is_active_language( $code ) ) {
					continue;
				}
				if ( $tt_id && ( (int) $menu->term_taxonomy_id === $tt_id || ! $this->is_nav_menu_tt_id( $tt_id ) ) ) {
					continue;
				}

				// Від'єднуємо меню, яке раніше було перекладом цією мовою.
				$group = $db->get_translations_by_trid( $trid );
				if ( isset( $group[ $code ] ) && (int) $group[ $code ]['element_id'] !== $tt_id ) {
					$db->set_element_language( (int) $group[ $code ]['element_id'], 'tax_nav_menu', $code, $db->next_trid() );
				}
				if ( $tt_id ) {
					$db->set_element_language( $tt_id, 'tax_nav_menu', $code, $trid, $lang );
				}
			}
		}

		wp_safe_redirect( admin_url( 'nav-menus.php?menu=' . ( $menu ? (int) $menu->term_id : 0 ) ) );
		exit;
	}

	private function is_nav_menu_tt_id( $tt_id ) {
		global $wpdb;
		return 'nav_menu' === $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) );
	}

	/* ------------------------------------------------------------------
	 * Сторінка налаштувань
	 * ------------------------------------------------------------------ */

	public function menu() {
		add_options_page(
			__( 'Мови сайту', 'agroeco-multilingual' ),
			__( 'Мови сайту', 'agroeco-multilingual' ),
			'manage_options',
			'agroeco-multilingual',
			array( $this, 'render_settings' )
		);
	}

	public function render_settings() {
		$s         = $this->plugin->settings->all();
		$menu      = wp_parse_args( (array) $s['menu_switcher'], AGML_Settings::defaults()['menu_switcher'] );
		$languages = $this->plugin->db->get_all_languages();
		?>
		<div class="wrap agml-settings">
			<h1><?php esc_html_e( 'Мови сайту', 'agroeco-multilingual' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agml_save_settings" />
				<?php wp_nonce_field( 'agml_save_settings' ); ?>

				<h2><?php esc_html_e( 'Мови', 'agroeco-multilingual' ); ?></h2>
				<table class="widefat striped agml-languages">
					<thead><tr>
						<th><?php esc_html_e( 'Активна', 'agroeco-multilingual' ); ?></th>
						<th><?php esc_html_e( 'За замовчуванням', 'agroeco-multilingual' ); ?></th>
						<th><?php esc_html_e( 'Мова', 'agroeco-multilingual' ); ?></th>
						<th><?php esc_html_e( 'Код', 'agroeco-multilingual' ); ?></th>
						<th><?php esc_html_e( 'Локаль', 'agroeco-multilingual' ); ?></th>
						<th><?php esc_html_e( 'Домен (для формату «домен»)', 'agroeco-multilingual' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $languages as $code => $language ) : ?>
						<tr>
							<td><input type="checkbox" name="active[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( $language['active'] ); ?> /></td>
							<td><input type="radio" name="default_language" value="<?php echo esc_attr( $code ); ?>" <?php checked( $code, $s['default_language'] ); ?> /></td>
							<td><?php echo $this->flag_html( $code ) . esc_html( $language['native_name'] . ' / ' . $language['english_name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><code><?php echo esc_html( $code ); ?></code></td>
							<td><code><?php echo esc_html( $language['locale'] ); ?></code></td>
							<td><input type="text" class="regular-text" name="domains[<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( isset( $s['domains'][ $code ] ) ? $s['domains'][ $code ] : '' ); ?>" placeholder="en.example.com" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Формат URL', 'agroeco-multilingual' ); ?></h2>
				<fieldset>
					<label><input type="radio" name="url_format" value="directory" <?php checked( $s['url_format'], 'directory' ); ?> /> <?php esc_html_e( 'Каталог: example.com/en/page/', 'agroeco-multilingual' ); ?></label><br />
					<label><input type="radio" name="url_format" value="parameter" <?php checked( $s['url_format'], 'parameter' ); ?> /> <?php esc_html_e( 'Параметр: example.com/page/?lang=en', 'agroeco-multilingual' ); ?></label><br />
					<label><input type="radio" name="url_format" value="domain" <?php checked( $s['url_format'], 'domain' ); ?> /> <?php esc_html_e( 'Окремий домен для кожної мови', 'agroeco-multilingual' ); ?></label><br />
					<label><input type="checkbox" name="default_in_directory" value="1" <?php checked( $s['default_in_directory'] ); ?> /> <?php esc_html_e( 'Додавати каталог і для мови за замовчуванням (example.com/uk/)', 'agroeco-multilingual' ); ?></label>
				</fieldset>

				<h2><?php esc_html_e( 'Що перекладається', 'agroeco-multilingual' ); ?></h2>
				<p><strong><?php esc_html_e( 'Типи записів:', 'agroeco-multilingual' ); ?></strong><br />
				<?php foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) : ?>
					<?php
					if ( in_array( $type->name, array( 'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true ) ) {
						continue;}
					?>
					<label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?> /> <?php echo esc_html( $type->labels->name ); ?></label>&nbsp;&nbsp;
				<?php endforeach; ?>
				</p>
				<p><strong><?php esc_html_e( 'Таксономії:', 'agroeco-multilingual' ); ?></strong><br />
				<?php foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) : ?>
					<label><input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( in_array( $tax->name, (array) $s['taxonomies'], true ) ); ?> /> <?php echo esc_html( $tax->labels->name ); ?></label>&nbsp;&nbsp;
				<?php endforeach; ?>
				</p>
				<p>
					<label><input type="checkbox" name="hreflang" value="1" <?php checked( $s['hreflang'] ); ?> /> <?php esc_html_e( 'Виводити теги hreflang', 'agroeco-multilingual' ); ?></label><br />
					<label><input type="checkbox" name="translate_gettext" value="1" <?php checked( $s['translate_gettext'] ); ?> /> <?php esc_html_e( 'Використовувати переклади рядків теми/плагінів із WPML String Translation', 'agroeco-multilingual' ); ?></label>
				</p>

				<h2><?php esc_html_e( 'Перемикач мов у меню (прапори)', 'agroeco-multilingual' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Додавати до меню', 'agroeco-multilingual' ); ?></th>
						<td>
							<?php foreach ( get_registered_nav_menus() as $location => $label ) : ?>
								<label><input type="checkbox" name="menu_switcher[locations][]" value="<?php echo esc_attr( $location ); ?>" <?php checked( in_array( $location, (array) $menu['locations'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Вигляд', 'agroeco-multilingual' ); ?></th>
						<td>
							<label><input type="radio" name="menu_switcher[style]" value="dropdown" <?php checked( $menu['style'], 'dropdown' ); ?> /> <?php esc_html_e( 'Випадне меню (поточна мова + підменю)', 'agroeco-multilingual' ); ?></label><br />
							<label><input type="radio" name="menu_switcher[style]" value="inline" <?php checked( $menu['style'], 'inline' ); ?> /> <?php esc_html_e( 'Окремі пункти в рядок', 'agroeco-multilingual' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Показувати', 'agroeco-multilingual' ); ?></th>
						<td>
							<label><input type="checkbox" name="menu_switcher[show_flag]" value="1" <?php checked( $menu['show_flag'] ); ?> /> <?php esc_html_e( 'Прапор', 'agroeco-multilingual' ); ?></label><br />
							<select name="menu_switcher[show_name]">
								<?php
								$names = array(
									'native'     => __( 'Назва мови її мовою (English, Українська)', 'agroeco-multilingual' ),
									'translated' => __( 'Назва мови поточною мовою', 'agroeco-multilingual' ),
									'both'       => __( 'Обидві назви', 'agroeco-multilingual' ),
									'code'       => __( 'Код (EN, UK)', 'agroeco-multilingual' ),
									'none'       => __( 'Без назви (лише прапор)', 'agroeco-multilingual' ),
								);
								foreach ( $names as $value => $label ) {
									printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $menu['show_name'], $value, false ), esc_html( $label ) );
								}
								?>
							</select><br />
							<label><input type="checkbox" name="menu_switcher[hide_current]" value="1" <?php checked( $menu['hide_current'] ); ?> /> <?php esc_html_e( 'Приховувати поточну мову (для вигляду «в рядок»)', 'agroeco-multilingual' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Розташування', 'agroeco-multilingual' ); ?></th>
						<td>
							<label><input type="radio" name="menu_switcher[position]" value="end" <?php checked( $menu['position'], 'end' ); ?> /> <?php esc_html_e( 'В кінці меню', 'agroeco-multilingual' ); ?></label>&nbsp;&nbsp;
							<label><input type="radio" name="menu_switcher[position]" value="start" <?php checked( $menu['position'], 'start' ); ?> /> <?php esc_html_e( 'На початку меню', 'agroeco-multilingual' ); ?></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Зберегти', 'agroeco-multilingual' ), 'primary', 'submit', false ); ?>
					<?php if ( get_option( 'icl_sitepress_settings' ) ) : ?>
						&nbsp;<button type="submit" name="import_wpml" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Перезаписати налаштування значеннями з WPML?', 'agroeco-multilingual' ) ); ?>');"><?php esc_html_e( 'Імпортувати налаштування з WPML', 'agroeco-multilingual' ); ?></button>
					<?php endif; ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Додати мову', 'agroeco-multilingual' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="agml-add-language">
				<input type="hidden" name="action" value="agml_add_language" />
				<?php wp_nonce_field( 'agml_add_language' ); ?>
				<input type="text" name="code" placeholder="de" required pattern="[a-z]{2,3}(-[a-z]{2,4})?" size="6" />
				<input type="text" name="english_name" placeholder="German" required />
				<input type="text" name="native_name" placeholder="Deutsch" required />
				<input type="text" name="locale" placeholder="de_DE" required size="8" />
				<input type="text" name="flag" placeholder="de.svg" size="10" />
				<?php submit_button( __( 'Додати', 'agroeco-multilingual' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Прапор: ім\'я файлу з каталогу прапорів WPML/плагіна або файлу в uploads/flags/.', 'agroeco-multilingual' ); ?></p>
			</form>

			<h2><?php esc_html_e( 'Вивід у шаблоні', 'agroeco-multilingual' ); ?></h2>
			<p><code>[agml_language_switcher style="list" flags="1" names="native" hide_current="0"]</code></p>
			<p><code>&lt;?php agml_language_switcher( array( 'style' =&gt; 'dropdown' ) ); ?&gt;</code></p>
		</div>
		<?php
	}

	public function save_settings() {
		check_admin_referer( 'agml_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'agroeco-multilingual' ) );
		}

		if ( ! empty( $_POST['import_wpml'] ) ) {
			$this->plugin->settings->update( AGML_Settings::from_wpml() );
			AGML_Install::copy_wpml_flags();
			wp_safe_redirect( admin_url( 'options-general.php?page=agroeco-multilingual&agml_notice=saved' ) );
			exit;
		}

		$post = wp_unslash( $_POST );
		$all  = array_keys( $this->plugin->db->get_all_languages() );

		$default = isset( $post['default_language'] ) ? sanitize_key( $post['default_language'] ) : $this->plugin->default_language();
		$default = in_array( $default, $all, true ) ? $default : $this->plugin->default_language();

		$active   = array_values( array_intersect( array_map( 'sanitize_key', isset( $post['active'] ) ? (array) $post['active'] : array() ), $all ) );
		$active[] = $default;
		$this->plugin->db->set_active_languages( array_unique( $active ) );

		$domains = array();
		foreach ( isset( $post['domains'] ) ? (array) $post['domains'] : array() as $code => $domain ) {
			$domain = trim( sanitize_text_field( $domain ) );
			if ( '' !== $domain && in_array( $code, $all, true ) ) {
				$domains[ $code ] = $domain;
			}
		}

		$menu_in = isset( $post['menu_switcher'] ) ? (array) $post['menu_switcher'] : array();
		$formats = array( AGML_Settings::URL_DIRECTORY, AGML_Settings::URL_PARAMETER, AGML_Settings::URL_DOMAIN );

		$this->plugin->settings->update(
			array(
				'default_language'     => $default,
				'url_format'           => isset( $post['url_format'] ) && in_array( $post['url_format'], $formats, true ) ? $post['url_format'] : AGML_Settings::URL_DIRECTORY,
				'default_in_directory' => ! empty( $post['default_in_directory'] ),
				'domains'              => $domains,
				'post_types'           => array_map( 'sanitize_key', isset( $post['post_types'] ) ? (array) $post['post_types'] : array() ),
				'taxonomies'           => array_map( 'sanitize_key', isset( $post['taxonomies'] ) ? (array) $post['taxonomies'] : array() ),
				'hreflang'             => ! empty( $post['hreflang'] ),
				'translate_gettext'    => ! empty( $post['translate_gettext'] ),
				'menu_switcher'        => array(
					'locations'    => array_map( 'sanitize_key', isset( $menu_in['locations'] ) ? (array) $menu_in['locations'] : array() ),
					'style'        => isset( $menu_in['style'] ) && 'inline' === $menu_in['style'] ? 'inline' : 'dropdown',
					'show_flag'    => ! empty( $menu_in['show_flag'] ),
					'show_name'    => isset( $menu_in['show_name'] ) && in_array( $menu_in['show_name'], array( 'native', 'translated', 'both', 'code', 'none' ), true ) ? $menu_in['show_name'] : 'native',
					'hide_current' => ! empty( $menu_in['hide_current'] ),
					'position'     => isset( $menu_in['position'] ) && 'start' === $menu_in['position'] ? 'start' : 'end',
				),
			)
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=agroeco-multilingual&agml_notice=saved' ) );
		exit;
	}

	public function add_language() {
		global $wpdb;

		check_admin_referer( 'agml_add_language' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'agroeco-multilingual' ) );
		}

		$post   = wp_unslash( $_POST );
		$code   = isset( $post['code'] ) ? strtolower( sanitize_text_field( $post['code'] ) ) : '';
		$name   = isset( $post['english_name'] ) ? sanitize_text_field( $post['english_name'] ) : '';
		$native = isset( $post['native_name'] ) ? sanitize_text_field( $post['native_name'] ) : '';
		$locale = isset( $post['locale'] ) ? sanitize_text_field( $post['locale'] ) : '';
		$flag   = isset( $post['flag'] ) && '' !== $post['flag'] ? sanitize_file_name( $post['flag'] ) : $code . '.svg';

		if ( ! preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/', $code ) || '' === $name || $this->plugin->is_known_language( $code ) ) {
			wp_die( esc_html__( 'Некоректний або вже наявний код мови.', 'agroeco-multilingual' ) );
		}

		$db = $this->plugin->db;
		$wpdb->insert(
			$db->table( 'languages' ),
			array(
				'code'           => $code,
				'english_name'   => $name,
				'major'          => 0,
				'active'         => 1,
				'default_locale' => $locale,
				'tag'            => str_replace( '_', '-', $code ),
			)
		);
		$wpdb->insert(
			$db->table( 'languages_translations' ),
			array(
				'language_code'         => $code,
				'display_language_code' => $code,
				'name'                  => $native ? $native : $name,
			)
		);
		$wpdb->replace(
			$db->table( 'flags' ),
			array(
				'lang_code'     => $code,
				'flag'          => $flag,
				'from_template' => file_exists( wp_upload_dir( null, false )['basedir'] . '/flags/' . $flag ) ? 1 : 0,
			)
		);
		$db->flush_language_cache();

		wp_safe_redirect( admin_url( 'options-general.php?page=agroeco-multilingual&agml_notice=saved' ) );
		exit;
	}
}
