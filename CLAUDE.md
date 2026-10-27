# CLAUDE.md — agroeco_wp

## Мета проєкту

Власний плагін багатомовності **Agroeco Multilingual** для WordPress-сайту, який зараз працює на
**WPML Multilingual CMS** і **WPML Flag in Menu Extended**. Плагін має замінити обидва, і **жодного
перекладу не доведеться створювати заново**. Для цього він читає й пише безпосередньо в таблиці WPML
(`wp_icl_*`), без міграції даних.

Користувач спілкується українською. Інтерфейс плагіна, коментарі в коді й документація теж українською.

## Стек

- PHP ≥ 7.4 (перевірено на 8.3), WordPress ≥ 5.8 (перевірено на 6.6.2), MySQL/MariaDB.
- Без Composer-залежностей і збірки JS/CSS: плагін — це звичайні PHP-файли та статичні assets.
- Тести: bash + curl + вбудований PHP-сервер (`php -S`), WordPress через Composer (`johnpbloch/wordpress-core`).

## Структура

```
agroeco-multilingual/            ← сам плагін (копіюється у wp-content/plugins/)
  agroeco-multilingual.php       bootstrap: константи, require, activation hook, boot на plugins_loaded:1
  includes/
    class-agml-plugin.php        ядро/синглтон agml(): створює модулі; мови; admin language; object_id()
    class-agml-db.php            доступ до icl_translations / icl_languages / icl_flags / icl_strings, кеш
    class-agml-settings.php      опція agml_settings; from_wpml() імпортує icl_sitepress_settings
    class-agml-router.php        визначення мови, зняття /en/ з REQUEST_URI, convert_url(), фільтри посилань, locale
    class-agml-query.php         фільтрація posts_where / terms_clauses / get_pages, сторінки за шляхом,
                                 однакові slug, page_on_front, меню за мовою
    class-agml-strings.php       переклади з WPML String Translation (blogname, віджети, gettext)
    class-agml-switcher.php      перемикач мов: пункти меню з прапорами, шорткод, hreflang
    class-agml-admin.php         адмінка: мова контенту, колонки, метабокс, створення перекладу, терміни,
                                 меню, сторінка «Налаштування → Мови сайту»; + хуки save_post/created_term
    class-agml-install.php       активація: таблиці (якщо WPML не було), базові мови, копія прапорів WPML
    compat-wpml.php              API WPML для тем: icl_object_id, icl_get_languages, icl_t, ICL_LANGUAGE_CODE,
                                 фільтри wpml_* (підключається лише коли WPML вимкнено)
  assets/                        agml.css, admin.css, flags/*.svg (запасні прапори)
  uninstall.php                  видаляє лише agml_settings і user meta, таблиці icl_* НЕ чіпає
  README.md                      інструкція для користувача (українською)
tests/
  lint.sh                        php -l по всіх PHP-файлах
  e2e/run.sh                     наскрізні тести (45 перевірок), див. нижче
  e2e/setup-data.php             наповнює базу даними у форматі WPML і активує плагін
  e2e/router.php                 роутер для php -S (імітує mod_rewrite)
  e2e/theme/                     мінімальна класична тема, що виводить маркери LANG=, TITLE=, BLOG= …
docs/STATUS.md                   поточний стан, баги, наступні кроки
```

## Збірка, запуск, тести

Збирати нічого не треба. Встановлення на сайт: скопіювати `agroeco-multilingual/` у `wp-content/plugins/`,
деактивувати (не видаляти!) WPML і WPML Flag in Menu Extended, активувати плагін.

```bash
./tests/lint.sh          # синтаксис PHP
./tests/e2e/run.sh       # e2e: exit 0 = усі перевірки пройшли
```

`tests/e2e/run.sh` потребує `php` (mysqli), `composer`, `curl` і MySQL/MariaDB з доступом root через сокет.
Інший доступ задається через `AGML_MYSQL="mysql -uUSER -pPASS"`. Якщо сервер не запущено, скрипт пробує
`mysqld_safe`. У хмарній сесії Claude Code MariaDB ставиться так: `apt-get update && apt-get install -y mariadb-server`.
Інші змінні: `AGML_PORT` (8090), `AGML_DB` (agml_test, база **перестворюється**), `AGML_WP_VERSION` (6.6.2).
WordPress, згенерований `wp-config.php` і `debug.log` лежать у `tests/e2e/.work/` (у .gitignore).
Паролі бази й адміна генеруються випадково на кожен запуск.

Ручна перевірка: після `run.sh` можна підняти сервер знову:
`php -S 127.0.0.1:8090 -t tests/e2e/.work/wordpress-6.6.2 tests/e2e/router.php`. Пароль адміна при цьому
невідомий, його можна скинути через `wp_set_password()` у php-скрипті.

Мережа в хмарній сесії: `wordpress.org` і `github.com` (codeload) заблоковані проксі, а Packagist і
Composer-дистрибутиви працюють.

## Домовленості щодо коду

- Стиль WordPress Coding Standards: таби, `array()`, пробіли в дужках, префікс `agml_` / `AGML_` для всього
  глобального, класи `AGML_*` у `includes/class-agml-*.php`.
- Коментарі та рядки інтерфейсу українською, text domain `agroeco-multilingual`.
- Будь-який SQL з даними лише через `$wpdb->prepare()`. Мову з запиту завжди звіряти з
  `is_active_language()`. Вивід екранувати `esc_html` / `esc_attr` / `esc_url`.
- Дії в адмінці: nonce (`wp_nonce_field` / `check_admin_referer`) + `current_user_can`.
  Перенаправлення лише через `wp_safe_redirect`.
- Домен для посилань брати з `get_option('home')` / налаштувань, **ніколи з `$_SERVER['HTTP_HOST']`**.
- Нова поведінка покривається перевіркою в `tests/e2e/run.sh`. Перед комітом: `lint.sh` + `e2e/run.sh`.
- Комітити в робочу гілку сесії. PR відкривати лише на прохання користувача.

## Ключові рішення та чому

1. **Працюємо з таблицями WPML як є, без міграції.** Це вимога користувача (не створювати контент
   повторно). До того ж повернення на WPML лишається можливим.
2. **Плагін нічого не робить, поки активний WPML** (перевірка `ICL_SITEPRESS_VERSION` у `boot()`), бо обидва
   фільтрують ті самі запити. Функції `icl_*` визначаються лише після цієї перевірки, тож WPML не впаде
   на повторному оголошенні функцій.
3. **`ICL_SITEPRESS_VERSION` і глобальний `$sitepress` навмисно не визначаються.** Інші плагіни (ACF, Yoast
   тощо) за цією ознакою почнуть викликати класи WPML, і це призведе до fatal error. Тему підтримуємо
   через функції `icl_*` і фільтри `wpml_*`.
4. **Мовний каталог /en/ знімається з `REQUEST_URI` у `do_parse_request` і повертається в `parse_request`**
   замість власних rewrite rules. Не треба скидати постійні посилання, а `redirect_canonical` бачить
   справжній URL.
5. **У SQL тип порівнюється з літералами (`element_type = 'post_page'`), а не через `CONCAT(…, post_type)`.**
   Таблиці WPML часто мають інший collation, ніж `wp_posts`, і запит падав з «Illegal mix of collations».
   Через це на тестовому стенді всі сторінки віддавали 404.
6. **Записи й терміни без рядка в `icl_translations` вважаються мовою за замовчуванням.**
7. **`terms_clauses` не фільтрується для `object_ids`, `include` і `suppress_filter`.** Інакше `term_exists()`
   «не бачив» рубрик інших мов, і переклад створювався без рубрики.
8. **Однакові slug різними мовами:** `request` перетворює `pagename` на `page_id` власним пошуком за шляхом
   із урахуванням мови, а `wp_unique_post_slug` дозволяє дубль, якщо конфлікт лише з іншою мовою.
9. **Хуки збереження (`save_post`, `created_term`, …) підключаються завжди, не лише в `is_admin()`,**
   бо редактор блоків зберігає через REST.
10. **Мова в AJAX/REST із адмінки береться з Referer** (`post.php?post=ID` → мова цього запису).
11. **Пункти меню перемикача мають від'ємні числові ID** (−1000, −1001…). Рядкові ID у PHP 7 нестрого
    дорівнюють 0, і `Walker_Nav_Menu` зламав би підменю.
12. **«Створити переклад» робить чернетку-копію** оригіналу (вміст, мета-поля, рубрики, замінені на перекладені).
    Це найближче до звичного процесу в WPML.
13. **Безпека:** URL перемикача будуються з домену з налаштувань (виправлено отруєння кешу через Host).
    Зміна мови адмінки захищена nonce. trid не може об'єднати елементи різних типів. У меню можна
    зв'язувати лише справжні меню.
14. **Під час видалення плагіна таблиці `icl_*` не видаляються**, бо в них переклади.
