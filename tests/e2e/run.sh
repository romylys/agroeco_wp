#!/usr/bin/env bash
# Наскрізні (e2e) тести плагіна на справжньому WordPress + MariaDB/MySQL.
#
# Що робить:
#   1. Завантажує ядро WordPress через Composer (кешується в tests/e2e/.work/).
#   2. Створює чисту тестову базу з даними у форматі WPML (setup-data.php).
#   3. Запускає вбудований PHP-сервер і перевіряє сайт і адмінку через curl.
#
# Потрібно: php (mysqli), composer, curl, запущений MySQL/MariaDB, доступ root через сокет
# (або задайте AGML_MYSQL="mysql -uUSER -pPASS"). Тестова база ПЕРЕСТВОРЮЄТЬСЯ.
#
# Змінні: AGML_PORT (8090), AGML_DB (agml_test), AGML_WP_VERSION (6.6.2), AGML_MYSQL (mysql).
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
WORK="$HERE/.work"
PORT="${AGML_PORT:-8090}"
HOST="127.0.0.1:$PORT"
BASE="http://$HOST"
DB="${AGML_DB:-agml_test}"
WP_VERSION="${AGML_WP_VERSION:-6.6.2}"
MYSQL="${AGML_MYSQL:-mysql}"
WP="$WORK/wordpress-$WP_VERSION"
JAR="$WORK/cookies.txt"
OUT="$WORK/out.html"
LOG="$WP/debug.log"

# Одноразові облікові дані лише для локальної тестової бази (генеруються на кожен запуск).
DB_PASS="$(php -r 'echo bin2hex(random_bytes(12));')"
ADMIN_PASS="$(php -r 'echo bin2hex(random_bytes(12));')"

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
die()  { echo "ERROR: $*" >&2; exit 2; }

mkdir -p "$WORK"

# ---------------------------------------------------------------- WordPress
if [ ! -f "$WP/wp-settings.php" ]; then
	echo "Завантаження WordPress $WP_VERSION..."
	composer create-project --quiet --no-interaction --no-scripts johnpbloch/wordpress-core "$WP" "$WP_VERSION" \
		|| die "не вдалося завантажити WordPress через composer"
fi

# ---------------------------------------------------------------- База даних
if ! $MYSQL -e 'SELECT 1' >/dev/null 2>&1; then
	echo "MySQL недоступний, пробую запустити mysqld_safe..."
	mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null
	(mysqld_safe --user=mysql >/dev/null 2>&1 &)
	for _ in $(seq 1 30); do $MYSQL -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
	$MYSQL -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL/MariaDB не запущено"
fi
$MYSQL -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;
	CREATE USER IF NOT EXISTS 'agml_test'@'localhost' IDENTIFIED BY '$DB_PASS';
	ALTER USER 'agml_test'@'localhost' IDENTIFIED BY '$DB_PASS';
	GRANT ALL ON \`$DB\`.* TO 'agml_test'@'localhost';" || die "не вдалося створити тестову базу"

SALT="$(php -r 'echo bin2hex(random_bytes(16));')"
cat > "$WP/wp-config.php" <<EOF
<?php
// Згенеровано tests/e2e/run.sh — лише для локальних тестів.
define( 'DB_NAME', '$DB' );
define( 'DB_USER', 'agml_test' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wp_';
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) {
	define( \$k, '$SALT' . \$k );
}
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
EOF

ln -sfn "$REPO/agroeco-multilingual" "$WP/wp-content/plugins/agroeco-multilingual"
rm -rf "$WP/wp-content/themes/agtest"
cp -r "$HERE/theme" "$WP/wp-content/themes/agtest"
: > "$LOG"

echo "Наповнення бази даними у форматі WPML..."
IDS="$(AGML_WP_DIR="$WP" AGML_HOST="$HOST" AGML_ADMIN_PASS="$ADMIN_PASS" php "$HERE/setup-data.php")" || die "setup-data.php завершився з помилкою"

# ---------------------------------------------------------------- Сервер
php -S "$HOST" -t "$WP" "$HERE/router.php" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null' EXIT
for _ in $(seq 1 20); do curl -s -o /dev/null "$BASE/wp-login.php" && break; sleep 0.3; done

get()      { curl -s -o "$OUT" -w '%{http_code}' "$@"; }
has()      { grep -qF -- "$1" "$OUT"; }
set_opt()  { AGML_WP_DIR="$WP" AGML_HOST="$HOST" php -r '$_SERVER["HTTP_HOST"]=getenv("AGML_HOST");$_SERVER["REQUEST_URI"]="/";require getenv("AGML_WP_DIR")."/wp-load.php";$s=get_option("agml_settings");'"$1"';update_option("agml_settings",$s);'; }

# page URL, expected substrings...
check_page() {
	local url="$1"; shift
	local code; code="$(get "$BASE$url")"
	if [ "$code" != "200" ]; then bad "$url → HTTP $code"; return; fi
	local s
	for s in "$@"; do
		if has "$s"; then ok "$url містить «$s»"; else bad "$url не містить «$s»"; fi
	done
}
check_absent() {
	local url="$1" s="$2"
	get "$BASE$url" >/dev/null
	if has "$s"; then bad "$url не повинен містити «$s»"; else ok "$url не містить «$s»"; fi
}

echo
echo "== Фронтенд: формат «каталог»"
check_page /                     'LANG=uk' 'TITLE=Головна' 'BLOG=Агроеко' 'GT=Читати далі' '<html lang="uk"'
check_page /en/                  'LANG=en' 'TITLE=Home EN' 'BLOG=Agroeco' 'GT=Read more EN' 'LOCALE=en_US'
check_page /about/               'TITLE=Про нас' 'hreflang="en" href="'"$BASE"'/en/about/"'
check_page /en/about/            'TITLE=About us' "href=\"$BASE/en/about/\""
check_page /about/team/          'TITLE=Команда'
check_page /en/about/team/       'TITLE=Team' "LINK=$BASE/en/about/team/"
check_page /news/                'TITLE=Перша новина' 'TITLE=Лише українською'
check_absent /news/              'First news'
check_page /en/news-en/          'TITLE=First news'
check_absent /en/news-en/        'Перша новина'
check_page /category/agro/       'TITLE=Перша новина'
check_page /en/category/agro-en/ 'TITLE=First news' 'ALLCATS=Agrotech'
check_absent /en/category/agro-en/ 'Агротехнології'
check_page /en/                  'agml-name">Українська' 'agml-flag' 'menu-item-has-children'
check_page /uk-only/             "href=\"$BASE/en/\""   # немає перекладу → головна англійською

echo "== Безпека: заголовок Host не потрапляє в посилання"
curl -s -H 'Host: evil.example' -o "$OUT" "$BASE/?s=news"
if has 'evil.example'; then bad 'Host-заголовок потрапив у посилання'; else ok 'посилання не містять підмінений Host'; fi

echo "== Формат «параметр»"
set_opt '$s["url_format"]="parameter";'
check_page '/about/?lang=en'     'TITLE=About us' "LINK=$BASE/about/?lang=en"
check_page '/?lang=en'           'TITLE=Home EN'

echo "== Формат «домен»"
set_opt '$s["url_format"]="domain";$s["domains"]=array("en"=>"localhost:'"$PORT"'");'
code="$(curl -s -H "Host: localhost:$PORT" -o "$OUT" -w '%{http_code}' "$BASE/about/")"
if [ "$code" = 200 ] && has 'TITLE=About us'; then ok 'en-домен показує англійську сторінку'; else bad "en-домен: HTTP $code"; fi
set_opt '$s["url_format"]="directory";$s["domains"]=array();'

echo "== Адмінка"
rm -f "$JAR"
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/wp-login.php"
code="$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "log=admin" --data-urlencode "pwd=$ADMIN_PASS" -d 'wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php")"
[ "$code" = 302 ] && ok 'вхід в адмінку' || bad "вхід в адмінку: HTTP $code"

curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$BASE/wp-admin/edit.php"
if has 'Перша новина' && ! has 'First news'; then ok 'список записів відфільтровано за мовою адмінки'; else bad 'список записів не відфільтровано'; fi

# Створення перекладу через «+»
TR_URL="$(grep -oE 'agml-tr agml-tr-missing" href="[^"]*"' "$OUT" | head -1 | sed -E 's/.*href="([^"]*)"/\1/; s/&#038;/\&/g; s/&amp;/\&/g')"
if [ -n "$TR_URL" ]; then
	LOC="$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{redirect_url}' "$TR_URL")"
	NEW_ID="$(echo "$LOC" | sed -nE 's/.*post=([0-9]+).*/\1/p')"
	LANG_ROW="$($MYSQL -N "$DB" -e "SELECT language_code FROM wp_icl_translations WHERE element_type='post_post' AND element_id=${NEW_ID:-0}")"
	CAT="$($MYSQL -N "$DB" -e "SELECT t.name FROM wp_term_relationships r JOIN wp_term_taxonomy tt USING(term_taxonomy_id) JOIN wp_terms t USING(term_id) WHERE r.object_id=${NEW_ID:-0} AND tt.taxonomy='category'")"
	[ "$LANG_ROW" = en ] && ok 'переклад створено з мовою en' || bad "мова перекладу: '$LANG_ROW'"
	[ "$CAT" = Agrotech ] && ok 'рубрику перекладу замінено на перекладену' || bad "рубрика перекладу: '$CAT'"
else
	bad 'не знайдено посилання «+» для створення перекладу'
fi

# CSRF: зміна мови адмінки без nonce ігнорується
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/wp-admin/edit.php?agml_admin_lang=all"
ADM="$($MYSQL -N "$DB" -e "SELECT meta_value FROM wp_usermeta WHERE meta_key='agml_admin_lang'")"
[ "$ADM" != all ] && ok 'зміна мови адмінки без nonce відхилена' || bad 'зміна мови адмінки спрацювала без nonce'

for page in "options-general.php?page=agroeco-multilingual" "nav-menus.php" "edit-tags.php?taxonomy=category" "edit.php?post_type=page"; do
	code="$(curl -s -c "$JAR" -b "$JAR" -o "$OUT" -w '%{http_code}' "$BASE/wp-admin/$page")"
	[ "$code" = 200 ] && ok "wp-admin/$page відкривається" || bad "wp-admin/$page → HTTP $code"
done

echo "== Журнал помилок"
ERRORS="$(grep -E 'PHP (Fatal|Warning|Notice|Deprecated)|WordPress database error' "$LOG" | grep -vE 'wp_version_check|wp_update_(plugins|themes)' || true)"
if [ -z "$ERRORS" ]; then ok 'debug.log без помилок'; else bad 'у debug.log є помилки:'; echo "$ERRORS" | head -20; fi

echo
echo "Результат: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
