<?php
/**
 * Фільтрація контенту за мовою: записи, терміни, меню, сторінки,
 * статична головна сторінка тощо.
 */

defined( 'ABSPATH' ) || exit;

class AGML_Query {

	/** @var AGML_Plugin */
	private $plugin;

	public function __construct( AGML_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'posts_where', array( $this, 'filter_posts_where' ), 10, 2 );
		add_filter( 'terms_clauses', array( $this, 'filter_terms_clauses' ), 10, 3 );
		add_filter( 'get_pages', array( $this, 'filter_get_pages' ), 10, 2 );
		add_filter( 'request', array( $this, 'resolve_hierarchical_paths' ) );
		add_filter( 'wp_unique_post_slug', array( $this, 'allow_slug_in_other_language' ), 10, 6 );
		add_filter( 'get_next_post_where', array( $this, 'filter_adjacent_where' ), 10, 5 );
		add_filter( 'get_previous_post_where', array( $this, 'filter_adjacent_where' ), 10, 5 );
		add_filter( 'getarchives_where', array( $this, 'filter_archives_where' ) );

		if ( ! is_admin() || wp_doing_ajax() ) {
			add_filter( 'option_page_on_front', array( $this, 'translate_page_option' ) );
			add_filter( 'option_page_for_posts', array( $this, 'translate_page_option' ) );
			add_filter( 'theme_mod_nav_menu_locations', array( $this, 'translate_menu_locations' ) );
			add_filter( 'wp_nav_menu_args', array( $this, 'translate_nav_menu_args' ) );
			add_filter( 'widget_nav_menu_args', array( $this, 'translate_nav_menu_args' ) );
		}
	}

	/**
	 * Мова, за якою фільтрується вибірка, або null (без фільтра).
	 */
	private function filter_language( $requested = null ) {
		if ( $requested ) {
			return 'all' === $requested ? null : $requested;
		}
		$lang = $this->plugin->current_language();
		return 'all' === $lang ? null : $lang;
	}

	/**
	 * SQL-умова «елемент належить мові $lang» для довільної таблиці.
	 *
	 * Елементи без запису в icl_translations вважаються мовою за замовчуванням.
	 *
	 * @param string   $id_col   Колонка з ID елемента (posts.ID або tt.term_taxonomy_id).
	 * @param string   $type_col Колонка з типом (posts.post_type або tt.taxonomy).
	 * @param string   $prefix   'post_' або 'tax_'.
	 * @param string[] $types    Типи, що перекладаються.
	 * @param string   $lang
	 */
	public function language_condition( $id_col, $type_col, $prefix, array $types, $lang ) {
		global $wpdb;

		if ( empty( $types ) ) {
			return '1=1';
		}

		$table     = $this->plugin->db->table( 'translations' );
		$types_sql = implode( ',', array_map( array( $this, 'quote' ), $types ) );
		$is_def    = $lang === $this->plugin->default_language();

		// Тип порівнюється з літералами, а не через CONCAT(колонка): таблиці WPML часто мають
		// інший collation, ніж wp_posts, і порівняння двох колонок дає «Illegal mix of collations».
		$branches = array( "{$type_col} NOT IN ({$types_sql})" );
		foreach ( $types as $type ) {
			$sub        = $wpdb->prepare(
				"SELECT 1 FROM {$table} agml_t WHERE agml_t.element_id = {$id_col} AND agml_t.element_type = %s AND agml_t.language_code " . ( $is_def ? '<>' : '=' ) . ' %s',
				$prefix . $type,
				$lang
			);
			$branches[] = $wpdb->prepare( "{$type_col} = %s AND ", $type ) . ( $is_def ? 'NOT ' : '' ) . "EXISTS ({$sub})";
		}

		return '( (' . implode( ') OR (', $branches ) . ') )';
	}

	private function quote( $value ) {
		global $wpdb;
		return $wpdb->prepare( '%s', $value );
	}

	/* ------------------------------------------------------------------
	 * Записи
	 * ------------------------------------------------------------------ */

	public function filter_posts_where( $where, $query ) {
		global $wpdb;

		$lang = $this->filter_language( $query->get( 'lang' ) );
		if ( ! $lang ) {
			return $where;
		}

		// Списки меню-елементів і вкладень не фільтруємо.
		$post_type = $query->get( 'post_type' );
		if ( is_string( $post_type ) && $post_type && ! $this->plugin->is_translatable_post_type( $post_type ) && 'any' !== $post_type ) {
			return $where;
		}

		return $where . ' AND ' . $this->language_condition(
			"{$wpdb->posts}.ID",
			"{$wpdb->posts}.post_type",
			'post_',
			$this->plugin->settings->post_types(),
			$lang
		);
	}

	public function filter_get_pages( $pages, $args ) {
		$lang = $this->filter_language( isset( $args['lang'] ) ? $args['lang'] : null );
		if ( ! $lang || empty( $pages ) ) {
			return $pages;
		}

		$default = $this->plugin->default_language();
		$result  = array();
		foreach ( $pages as $page ) {
			if ( ! $this->plugin->is_translatable_post_type( $page->post_type ) ) {
				$result[] = $page;
				continue;
			}
			$page_lang = $this->plugin->db->get_element_language( $page->ID, 'post_' . $page->post_type );
			if ( ( $page_lang ? $page_lang : $default ) === $lang ) {
				$result[] = $page;
			}
		}
		return $result;
	}

	public function filter_adjacent_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		$post = get_post( $post );
		if ( ! $post || ! $this->plugin->is_translatable_post_type( $post->post_type ) ) {
			return $where;
		}
		$lang = $this->plugin->db->get_element_language( $post->ID, 'post_' . $post->post_type );
		$lang = $lang ? $lang : $this->plugin->default_language();

		return $where . ' AND ' . $this->language_condition( 'p.ID', 'p.post_type', 'post_', $this->plugin->settings->post_types(), $lang );
	}

	public function filter_archives_where( $where ) {
		global $wpdb;

		$lang = $this->filter_language();
		if ( ! $lang ) {
			return $where;
		}
		return $where . ' AND ' . $this->language_condition( "{$wpdb->posts}.ID", "{$wpdb->posts}.post_type", 'post_', $this->plugin->settings->post_types(), $lang );
	}

	/**
	 * Сторінки різними мовами можуть мати однаковий slug (наприклад, /about/ та /en/about/).
	 * get_page_by_path() про мови не знає, тому знаходимо сторінку потрібною мовою самі.
	 */
	public function resolve_hierarchical_paths( $vars ) {
		$lang = $this->filter_language();
		if ( ! $lang ) {
			return $vars;
		}

		if ( ! empty( $vars['pagename'] ) && $this->plugin->is_translatable_post_type( 'page' ) ) {
			$id = $this->find_post_by_path( $vars['pagename'], 'page', $lang );
			if ( $id ) {
				unset( $vars['pagename'] );
				$vars['page_id'] = $id;
			}
			return $vars;
		}

		foreach ( get_post_types( array( 'hierarchical' => true ), 'objects' ) as $type ) {
			if ( 'page' === $type->name || empty( $type->query_var ) || empty( $vars[ $type->query_var ] ) || ! $this->plugin->is_translatable_post_type( $type->name ) ) {
				continue;
			}
			$id = $this->find_post_by_path( $vars[ $type->query_var ], $type->name, $lang );
			if ( $id ) {
				unset( $vars[ $type->query_var ], $vars['name'] );
				$vars['p']         = $id;
				$vars['post_type'] = $type->name;
			}
		}

		return $vars;
	}

	/**
	 * ID запису типу $post_type за ієрархічним шляхом мовою $lang.
	 */
	public function find_post_by_path( $path, $post_type, $lang ) {
		global $wpdb;

		$parts = array_values( array_filter( explode( '/', trim( rawurldecode( $path ), '/' ) ), 'strlen' ) );
		if ( empty( $parts ) ) {
			return 0;
		}
		$parts = array_map( 'sanitize_title_for_query', $parts );
		$leaf  = end( $parts );

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('trash', 'auto-draft') ORDER BY ID",
				$leaf,
				$post_type
			)
		);

		$default = $this->plugin->default_language();
		foreach ( (array) $candidates as $candidate ) {
			$cand_lang = $this->plugin->db->get_element_language( $candidate->ID, 'post_' . $post_type );
			if ( ( $cand_lang ? $cand_lang : $default ) !== $lang ) {
				continue;
			}

			// Перевіряємо ланцюжок батьківських сторінок.
			$parent = (int) $candidate->post_parent;
			$ok     = true;
			for ( $i = count( $parts ) - 2; $i >= 0; $i-- ) {
				$parent_post = $parent ? get_post( $parent ) : null;
				if ( ! $parent_post || urldecode( $parent_post->post_name ) !== urldecode( $parts[ $i ] ) ) {
					$ok = false;
					break;
				}
				$parent = (int) $parent_post->post_parent;
			}
			if ( $ok && 0 === $parent ) {
				return (int) $candidate->ID;
			}
		}

		return 0;
	}

	/**
	 * Дозволяє однаковий slug у записів різними мовами (як у WPML).
	 */
	public function allow_slug_in_other_language( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		global $wpdb;

		if ( $slug === $original_slug || ! $this->plugin->is_translatable_post_type( $post_type ) ) {
			return $slug;
		}

		$lang = $this->plugin->language_for_saved_post( $post_id, $post_type );

		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d AND post_status NOT IN ('trash', 'auto-draft')",
			$original_slug,
			$post_type,
			$post_id
		);
		if ( is_post_type_hierarchical( $post_type ) ) {
			$sql .= $wpdb->prepare( ' AND post_parent = %d', $post_parent );
		}
		$conflicts = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $conflicts ) ) {
			return $slug; // Slug змінено з іншої причини (зарезервоване слово тощо).
		}

		$default = $this->plugin->default_language();
		foreach ( $conflicts as $conflict_id ) {
			$conflict_lang = $this->plugin->db->get_element_language( $conflict_id, 'post_' . $post_type );
			if ( ( $conflict_lang ? $conflict_lang : $default ) === $lang ) {
				return $slug;
			}
		}

		return $original_slug;
	}

	/* ------------------------------------------------------------------
	 * Терміни
	 * ------------------------------------------------------------------ */

	public function filter_terms_clauses( $clauses, $taxonomies, $args ) {
		// Терміни конкретного запису, вибірку за явними ID та службові перевірки (term_exists()
		// передає suppress_filter) не фільтруємо — інакше WordPress «не бачить» терміни інших мов.
		if ( ! empty( $args['object_ids'] ) || ! empty( $args['include'] ) || ! empty( $args['suppress_filter'] ) ) {
			return $clauses;
		}

		$lang = $this->filter_language( isset( $args['lang'] ) ? $args['lang'] : null );
		if ( ! $lang ) {
			return $clauses;
		}

		$translatable = $this->plugin->settings->taxonomies();
		if ( ! empty( $taxonomies ) && ! array_intersect( (array) $taxonomies, $translatable ) ) {
			return $clauses;
		}

		$clauses['where'] .= ' AND ' . $this->language_condition( 'tt.term_taxonomy_id', 'tt.taxonomy', 'tax_', $translatable, $lang );
		return $clauses;
	}

	/* ------------------------------------------------------------------
	 * Головна сторінка та меню
	 * ------------------------------------------------------------------ */

	public function translate_page_option( $page_id ) {
		if ( ! $page_id ) {
			return $page_id;
		}
		$translated = $this->plugin->db->get_translated_id( $page_id, 'post_page', $this->plugin->current_language() );
		return $translated ? $translated : $page_id;
	}

	public function translate_menu_id( $menu_id ) {
		global $wpdb;

		$term = get_term( (int) $menu_id, 'nav_menu' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $menu_id;
		}

		$tt_id = $this->plugin->db->get_translated_id( $term->term_taxonomy_id, 'tax_nav_menu', $this->plugin->current_language() );
		if ( ! $tt_id ) {
			return $menu_id;
		}

		$term_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) );
		return $term_id ? (int) $term_id : $menu_id;
	}

	public function translate_menu_locations( $locations ) {
		if ( ! is_array( $locations ) ) {
			return $locations;
		}
		foreach ( $locations as $location => $menu_id ) {
			if ( $menu_id ) {
				$locations[ $location ] = $this->translate_menu_id( $menu_id );
			}
		}
		return $locations;
	}

	public function translate_nav_menu_args( $args ) {
		if ( empty( $args['menu'] ) ) {
			return $args;
		}

		$menu = $args['menu'];
		if ( $menu instanceof WP_Term ) {
			$translated   = $this->translate_menu_id( $menu->term_id );
			$args['menu'] = $translated === $menu->term_id ? $menu : get_term( $translated, 'nav_menu' );
		} elseif ( is_numeric( $menu ) ) {
			$args['menu'] = $this->translate_menu_id( (int) $menu );
		} else {
			$term = wp_get_nav_menu_object( $menu );
			if ( $term ) {
				$args['menu'] = $this->translate_menu_id( $term->term_id );
			}
		}
		return $args;
	}
}
