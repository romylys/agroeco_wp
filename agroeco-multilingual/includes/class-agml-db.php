<?php
/**
 * Доступ до даних у WPML-сумісних таблицях (icl_*).
 *
 * Плагін не мігрує дані: він читає й пише в ті самі таблиці, що й WPML,
 * тож усі вже створені переклади, меню та рядки доступні одразу.
 */

defined( 'ABSPATH' ) || exit;

class AGML_DB {

	/** @var array<string,array>|null */
	private $languages;

	/** @var array<string,array> Кеш рядків icl_translations за ключем "type:id". */
	private $element_cache = array();

	/** @var array<int,array> Кеш перекладів за trid. */
	private $trid_cache = array();

	public function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'icl_' . $name;
	}

	public function tables_exist() {
		global $wpdb;
		$table = $this->table( 'translations' );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/* ------------------------------------------------------------------
	 * Мови
	 * ------------------------------------------------------------------ */

	/**
	 * Усі мови з таблиці icl_languages, включно з неактивними.
	 *
	 * @return array<string,array> code => [code, english_name, native_name, active, locale, tag, flag_url]
	 */
	public function get_all_languages() {
		global $wpdb;

		if ( null !== $this->languages ) {
			return $this->languages;
		}

		$cached = wp_cache_get( 'languages', 'agml' );
		if ( is_array( $cached ) ) {
			$this->languages = $cached;
			return $cached;
		}

		$this->languages = array();
		if ( ! $this->tables_exist() ) {
			return $this->languages;
		}

		$rows = $wpdb->get_results(
			"SELECT l.code, l.english_name, l.active, l.default_locale, l.tag, lt.name AS native_name, f.flag, f.from_template
			FROM {$this->table( 'languages' )} l
			LEFT JOIN {$this->table( 'languages_translations' )} lt
				ON lt.language_code = l.code AND lt.display_language_code = l.code
			LEFT JOIN {$this->table( 'flags' )} f ON f.lang_code = l.code
			ORDER BY l.english_name",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$this->languages[ $row['code'] ] = array(
				'code'          => $row['code'],
				'english_name'  => $row['english_name'],
				'native_name'   => $row['native_name'] ? $row['native_name'] : $row['english_name'],
				'active'        => (bool) $row['active'],
				'locale'        => $row['default_locale'] ? $row['default_locale'] : $row['code'],
				'tag'           => $row['tag'] ? $row['tag'] : $row['code'],
				'flag'          => (string) $row['flag'],
				'from_template' => (bool) $row['from_template'],
			);
		}

		wp_cache_set( 'languages', $this->languages, 'agml' );
		return $this->languages;
	}

	/**
	 * Назва мови $code, перекладена мовою $display_code.
	 */
	public function get_language_name( $code, $display_code ) {
		global $wpdb;

		$key  = 'name_' . $code . '_' . $display_code;
		$name = wp_cache_get( $key, 'agml' );
		if ( false === $name ) {
			$name = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT name FROM {$this->table( 'languages_translations' )} WHERE language_code = %s AND display_language_code = %s",
					$code,
					$display_code
				)
			);
			wp_cache_set( $key, $name, 'agml' );
		}

		if ( '' === $name ) {
			$all  = $this->get_all_languages();
			$name = isset( $all[ $code ] ) ? $all[ $code ]['english_name'] : $code;
		}
		return $name;
	}

	public function set_active_languages( array $codes ) {
		global $wpdb;
		$table = $this->table( 'languages' );
		$wpdb->query( "UPDATE {$table} SET active = 0" );
		foreach ( $codes as $code ) {
			$wpdb->update( $table, array( 'active' => 1 ), array( 'code' => $code ) );
		}
		$this->flush_language_cache();
	}

	public function flush_language_cache() {
		$this->languages = null;
		wp_cache_delete( 'languages', 'agml' );
	}

	/* ------------------------------------------------------------------
	 * Переклади елементів (записи, терміни, меню)
	 * ------------------------------------------------------------------ */

	/**
	 * Рядок icl_translations для елемента.
	 *
	 * @param int    $element_id   ID запису або term_taxonomy_id терміна.
	 * @param string $element_type 'post_{post_type}' або 'tax_{taxonomy}'.
	 * @return array|null
	 */
	public function get_element( $element_id, $element_type ) {
		global $wpdb;

		$element_id = (int) $element_id;
		$key        = $element_type . ':' . $element_id;

		if ( ! array_key_exists( $key, $this->element_cache ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT translation_id, element_type, element_id, trid, language_code, source_language_code
					FROM {$this->table( 'translations' )} WHERE element_id = %d AND element_type = %s",
					$element_id,
					$element_type
				),
				ARRAY_A
			);
			$this->element_cache[ $key ] = $row ? $row : null;
		}

		return $this->element_cache[ $key ];
	}

	public function get_element_language( $element_id, $element_type ) {
		$row = $this->get_element( $element_id, $element_type );
		return $row ? $row['language_code'] : null;
	}

	/**
	 * Усі переклади групи trid.
	 *
	 * @return array<string,array> language_code => row
	 */
	public function get_translations_by_trid( $trid ) {
		global $wpdb;

		$trid = (int) $trid;
		if ( ! $trid ) {
			return array();
		}

		if ( ! isset( $this->trid_cache[ $trid ] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT translation_id, element_type, element_id, trid, language_code, source_language_code
					FROM {$this->table( 'translations' )} WHERE trid = %d AND element_id IS NOT NULL",
					$trid
				),
				ARRAY_A
			);

			$this->trid_cache[ $trid ] = array();
			foreach ( (array) $rows as $row ) {
				$this->trid_cache[ $trid ][ $row['language_code'] ] = $row;
			}
		}

		return $this->trid_cache[ $trid ];
	}

	/**
	 * @return array<string,array> language_code => row
	 */
	public function get_element_translations( $element_id, $element_type ) {
		$row = $this->get_element( $element_id, $element_type );
		return $row ? $this->get_translations_by_trid( $row['trid'] ) : array();
	}

	/**
	 * ID перекладу елемента на мову $lang або null.
	 */
	public function get_translated_id( $element_id, $element_type, $lang ) {
		$translations = $this->get_element_translations( $element_id, $element_type );
		return isset( $translations[ $lang ] ) ? (int) $translations[ $lang ]['element_id'] : null;
	}

	/**
	 * Створює або оновлює мовний запис елемента.
	 *
	 * @param int         $element_id
	 * @param string      $element_type
	 * @param string      $lang
	 * @param int|null    $trid        Група перекладів; null — створити нову.
	 * @param string|null $source_lang Мова оригіналу.
	 * @return int trid
	 */
	public function set_element_language( $element_id, $element_type, $lang, $trid = null, $source_lang = null ) {
		global $wpdb;

		$table    = $this->table( 'translations' );
		$existing = $this->get_element( $element_id, $element_type );

		if ( ! $trid ) {
			$trid = $existing ? (int) $existing['trid'] : $this->next_trid();
		}

		// Група trid може містити лише елементи того самого типу (запис не можна
		// зробити «перекладом» рубрики чи меню через підроблений trid у формі).
		$taken = $this->get_translations_by_trid( $trid );
		foreach ( $taken as $member ) {
			if ( $member['element_type'] !== $element_type ) {
				$trid        = $existing && (int) $existing['trid'] !== (int) $trid ? (int) $existing['trid'] : $this->next_trid();
				$source_lang = null;
				$taken       = $this->get_translations_by_trid( $trid );
				break;
			}
		}

		// В одній групі trid може бути лише один елемент кожною мовою.
		if ( isset( $taken[ $lang ] ) && (int) $taken[ $lang ]['element_id'] !== (int) $element_id ) {
			$trid        = $this->next_trid();
			$source_lang = null;
		}

		$data = array(
			'element_type'         => $element_type,
			'element_id'           => (int) $element_id,
			'trid'                 => (int) $trid,
			'language_code'        => $lang,
			'source_language_code' => $source_lang ? $source_lang : null,
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'translation_id' => (int) $existing['translation_id'] ) );
		} else {
			$wpdb->insert( $table, $data );
		}

		$this->flush_element_cache();
		return (int) $trid;
	}

	public function delete_element( $element_id, $element_type ) {
		global $wpdb;
		$wpdb->delete(
			$this->table( 'translations' ),
			array(
				'element_id'   => (int) $element_id,
				'element_type' => $element_type,
			)
		);
		$this->flush_element_cache();
	}

	public function next_trid() {
		global $wpdb;
		return 1 + (int) $wpdb->get_var( "SELECT MAX(trid) FROM {$this->table( 'translations' )}" );
	}

	public function flush_element_cache() {
		$this->element_cache = array();
		$this->trid_cache    = array();
	}

	/* ------------------------------------------------------------------
	 * Прапори
	 * ------------------------------------------------------------------ */

	public function get_flag_url( $code ) {
		$languages = $this->get_all_languages();
		if ( ! isset( $languages[ $code ] ) ) {
			return '';
		}

		$flag    = basename( $languages[ $code ]['flag'] ? $languages[ $code ]['flag'] : $code . '.svg' );
		$uploads = wp_upload_dir( null, false );

		$candidates = array();
		if ( $languages[ $code ]['from_template'] ) {
			// Власні прапори, завантажені через WPML, лежать у uploads/flags.
			$candidates[] = array( $uploads['basedir'] . '/flags/' . $flag, $uploads['baseurl'] . '/flags/' . $flag );
		}
		// Копія прапорів WPML, зроблена під час активації (працює й після видалення WPML).
		$candidates[] = array( $uploads['basedir'] . '/agroeco-multilingual/flags/' . $flag, $uploads['baseurl'] . '/agroeco-multilingual/flags/' . $flag );
		$candidates[] = array( WP_PLUGIN_DIR . '/sitepress-multilingual-cms/res/flags/' . $flag, WP_PLUGIN_URL . '/sitepress-multilingual-cms/res/flags/' . $flag );
		$candidates[] = array( AGML_DIR . 'assets/flags/' . $flag, AGML_URL . 'assets/flags/' . $flag );
		$candidates[] = array( AGML_DIR . 'assets/flags/' . $code . '.svg', AGML_URL . 'assets/flags/' . $code . '.svg' );

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate[0] ) ) {
				return $candidate[1];
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * Рядки (WPML String Translation)
	 * ------------------------------------------------------------------ */

	public function strings_tables_exist() {
		global $wpdb;
		static $exists = null;
		if ( null === $exists ) {
			$table  = $this->table( 'strings' );
			$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		return $exists;
	}

	/**
	 * Готові переклади всіх рядків контексту (домену) мовою $lang.
	 *
	 * @return array{by_name:array<string,string>,by_value:array<string,string>}
	 */
	public function get_context_strings( $context, $lang ) {
		global $wpdb;

		$key    = 'ctx_' . md5( $context ) . '_' . $lang;
		$result = wp_cache_get( $key, 'agml' );
		if ( is_array( $result ) ) {
			return $result;
		}

		$result = array(
			'by_name'  => array(),
			'by_value' => array(),
		);

		if ( $this->strings_tables_exist() ) {
			// status 10 = ICL_TM_COMPLETE у WPML.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.name, s.value, st.value AS translation
					FROM {$this->table( 'strings' )} s
					INNER JOIN {$this->table( 'string_translations' )} st ON st.string_id = s.id
					WHERE s.context = %s AND st.language = %s AND st.status = 10 AND st.value IS NOT NULL AND st.value <> ''",
					$context,
					$lang
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$result['by_name'][ $row['name'] ]   = $row['translation'];
				$result['by_value'][ $row['value'] ] = $row['translation'];
			}
		}

		wp_cache_set( $key, $result, 'agml', HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Реєструє рядок (аналог icl_register_string), якщо його ще немає.
	 */
	public function register_string( $context, $name, $value, $lang ) {
		global $wpdb;

		if ( ! $this->strings_tables_exist() ) {
			return;
		}

		$table = $this->table( 'strings' );
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE context = %s AND name = %s",
				$context,
				$name
			)
		);

		if ( $id ) {
			$wpdb->update( $table, array( 'value' => $value ), array( 'id' => (int) $id ) );
		} else {
			$wpdb->insert(
				$table,
				array(
					'language'                => $lang,
					'context'                 => $context,
					'name'                    => $name,
					'value'                   => $value,
					'gettext_context'         => '',
					'domain_name_context_md5' => md5( $context . $name ),
					'status'                  => 0,
				)
			);
		}
	}
}
