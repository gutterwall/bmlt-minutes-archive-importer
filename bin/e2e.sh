#!/usr/bin/env bash
# End-to-end test of the release zip on real servers, with real browser uploads:
#
#   WordPress (latest, default block theme) with BMLT Minutes (from wordpress.org) and the importer zip, served by
#     ├─ Apache + PHP-FPM
#     └─ nginx + PHP-FPM
#   PHP limits set low (max_file_uploads 4, upload_max_filesize 6M, post_max_size 10M) to reach them
#   headless Chrome as the administrator, a committee-limited Minutes Manager and a visitor (bin/browser-e2e.py)
#
# Single Import (PDF, Google Docs link, refused JPEG, duplicate), Bulk Import (review table, apply to all, per-row
# changes, skipped row, report), server limits, committee scope, the public minutes page with every document
# downloadable, then uninstall (settings gone, minutes kept).
#
# Usage: bin/e2e.sh <work-dir>      (the directory is created; KEEP=1 leaves the servers' files behind)
#
# Environment (defaults suit Ubuntu with apache2, nginx, php-fpm and google-chrome installed):
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_ADMIN_USER=root DB_ADMIN_PASS=
#   APACHE=apache2  APACHE_MODULES=/usr/lib/apache2/modules  NGINX=nginx  PHP_FPM=php-fpm8.3  CHROME=google-chrome
#   WP_CORE=<directory with WordPress files> (default: wp core download)   PORT_BASE=8580
#   PLUGIN_ZIP (default: this version's zip in dist/, built if missing)
#   MINUTES_ZIP (default: tests/dependencies/bmlt-minutes.zip from bin/install-bmlt-minutes.sh)
set -euo pipefail

[ $# -eq 1 ] || { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
ROOT=$(cd "$(dirname "$0")/.." && pwd)
W=$(mkdir -p "$1" && cd "$1" && pwd)
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_ADMIN_USER=${DB_ADMIN_USER:-root}
DB_ADMIN_PASS=${DB_ADMIN_PASS:-}
APACHE=${APACHE:-apache2}
APACHE_MODULES=${APACHE_MODULES:-/usr/lib/apache2/modules}
NGINX=${NGINX:-nginx}
PHP_FPM=${PHP_FPM:-php-fpm8.3}
P=${PORT_BASE:-8580}
APACHE_PORT=$((P + 3)) NGINX_PORT=$((P + 4))
SITE_A="http://127.0.0.1:$APACHE_PORT"
SITE_N="http://127.0.0.1:$NGINX_PORT"

pass() { echo "  ok   $*"; }
fail() { echo "  FAIL $*" >&2; exit 1; }
section() { echo; echo "== $*"; }

PL=bmlt-minutes-archive-importer
V=$(sed -n 's/^ \* Version: *//p' "$ROOT/$PL/$PL.php")
# PLUGIN_ZIP tests another zip (for example the previous release, to see the test fail).
PLUGIN_ZIP=${PLUGIN_ZIP:-$ROOT/dist/$PL-$V.zip}
[ -f "$PLUGIN_ZIP" ] || "$ROOT/bin/build.sh" --dev --allow-dirty
MINUTES_ZIP=${MINUTES_ZIP:-$ROOT/tests/dependencies/bmlt-minutes.zip}
[ -f "$MINUTES_ZIP" ] || "$ROOT/bin/install-bmlt-minutes.sh"

PIDS=()
cleanup() {
	for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
	[ -f "$W/run/apache.pid" ] && kill "$(cat "$W/run/apache.pid")" 2>/dev/null || true
	mysql_admin -e "DROP DATABASE IF EXISTS e2e_mai" 2>/dev/null || true
	if [ "${KEEP:-0}" != 1 ]; then rm -rf "$W"; fi
}
trap cleanup EXIT
mysql_admin() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_ADMIN_USER" ${DB_ADMIN_PASS:+-p"$DB_ADMIN_PASS"} "$@"; }

rm -rf "${W:?}"/*
mkdir -p "$W/run" "$W/logs" "$W/nginx"

section "PHP-FPM, Apache, nginx"
cat > "$W/run/fpm.conf" <<EOF
[global]
error_log = $W/logs/fpm.log
pid = $W/run/fpm.pid
daemonize = no
[wp]
listen = $W/run/fpm.sock
pm = static
pm.max_children = 8
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = $W/logs/php.log
php_admin_flag[log_errors] = on
; As on a production server: PHP warnings go to the log, not into pages.
php_admin_flag[display_errors] = off
; Low limits, so the test can reach them.
php_admin_value[max_file_uploads] = 4
php_admin_value[upload_max_filesize] = 6M
php_admin_value[post_max_size] = 10M
EOF
"$PHP_FPM" -y "$W/run/fpm.conf" -F >"$W/logs/fpm.out" 2>&1 &
PIDS+=($!)

mod() { echo "LoadModule ${1}_module $APACHE_MODULES/mod_$1.so"; }
{
	echo "ServerRoot $W/run"
	echo "ServerName 127.0.0.1"
	echo "PidFile $W/run/apache.pid"
	echo "ErrorLog $W/logs/apache-error.log"
	echo "Mutex file:$W/run"
	echo "<IfModule !mpm_event_module>"; mod mpm_event; echo "</IfModule>"
	echo "<IfModule !unixd_module>"; mod unixd; echo "</IfModule>"
	for m in authz_core authz_host access_compat dir mime rewrite proxy proxy_fcgi setenvif headers env log_config; do
		[ -f "$APACHE_MODULES/mod_$m.so" ] && echo "<IfModule !${m}_module>" && mod "$m" && echo "</IfModule>"
	done
	cat <<EOF
LogFormat "%h \"%r\" %>s" e2e
TypesConfig /dev/null
AddType text/css .css
AddType application/javascript .js
AddType text/plain .txt .md
DirectoryIndex index.php index.html
<Directory />
	AllowOverride None
	Require all denied
</Directory>
Listen 127.0.0.1:$APACHE_PORT
<Directory $W/wp>
	AllowOverride All
	Require all granted
	<FilesMatch "\.php$">
		SetHandler "proxy:unix:$W/run/fpm.sock|fcgi://wp"
	</FilesMatch>
</Directory>
<VirtualHost 127.0.0.1:$APACHE_PORT>
	DocumentRoot $W/wp
	CustomLog $W/logs/apache-access.log e2e
</VirtualHost>
EOF
} > "$W/run/apache.conf"
"$APACHE" -f "$W/run/apache.conf" -t >/dev/null 2>"$W/logs/apache-configtest.log" || { cat "$W/logs/apache-configtest.log"; fail "apache configuration"; }

cat > "$W/nginx/nginx.conf" <<EOF
pid $W/run/nginx.pid;
error_log $W/logs/nginx-error.log;
daemon off;
worker_processes 1;
events { worker_connections 64; }
http {
	access_log $W/logs/nginx-access.log;
	client_body_temp_path $W/run/nb; proxy_temp_path $W/run/np; fastcgi_temp_path $W/run/nf; uwsgi_temp_path $W/run/nu; scgi_temp_path $W/run/ns;
	types { text/html html; text/css css; application/javascript js; image/svg+xml svg; }
	default_type application/octet-stream;
	# The usual WordPress server block (wordpress.org's nginx guide).
	server {
		listen 127.0.0.1:$NGINX_PORT;
		server_name 127.0.0.1;
		root $W/wp;
		index index.php;
		client_max_body_size 64m;
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ \.php\$ {
			try_files \$uri =404;
			fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
			fastcgi_param QUERY_STRING \$query_string; fastcgi_param REQUEST_METHOD \$request_method;
			fastcgi_param CONTENT_TYPE \$content_type; fastcgi_param CONTENT_LENGTH \$content_length;
			fastcgi_param SCRIPT_NAME \$fastcgi_script_name; fastcgi_param REQUEST_URI \$request_uri;
			fastcgi_param DOCUMENT_URI \$document_uri; fastcgi_param DOCUMENT_ROOT \$document_root;
			fastcgi_param SERVER_PROTOCOL \$server_protocol; fastcgi_param REMOTE_ADDR \$remote_addr;
			fastcgi_param REMOTE_PORT \$remote_port; fastcgi_param SERVER_ADDR \$server_addr;
			fastcgi_param SERVER_PORT \$server_port; fastcgi_param SERVER_NAME \$server_name;
			fastcgi_param HTTP_HOST \$http_host;
			fastcgi_pass unix:$W/run/fpm.sock;
		}
	}
}
EOF

section "WordPress with the plugin zip"
WP=(wp --path="$W/wp" --quiet)
if [ -n "${WP_CORE:-}" ]; then
	mkdir -p "$W/wp" && (cd "$WP_CORE" && tar --exclude=./wp-config.php --exclude=./wp-content/uploads -cf - .) | tar -xf - -C "$W/wp"
else
	"${WP[@]}" core download --path="$W/wp" >/dev/null
fi
mysql_admin -e "DROP DATABASE IF EXISTS e2e_mai; CREATE DATABASE e2e_mai CHARACTER SET utf8mb4"
"${WP[@]}" config create --dbname=e2e_mai --dbuser="$DB_ADMIN_USER" --dbpass="$DB_ADMIN_PASS" --dbhost="$DB_HOST:$DB_PORT" --skip-check --force \
	--extra-php <<'EOF'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
// The same site behind Apache and nginx (two ports): links follow the address used.
if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
	define( 'WP_HOME', 'http://' . $_SERVER['HTTP_HOST'] );
	define( 'WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST'] );
}
EOF
"${WP[@]}" core install --url="$SITE_A" --title="E2E Region" --admin_user=admin --admin_password=Admin-pass-1 --admin_email=admin@example.org --skip-email
"${WP[@]}" rewrite structure '/%postname%/'
# WordPress's standard rules (what Settings → Permalinks writes on Apache).
cat > "$W/wp/.htaccess" <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
mkdir -p "$W/wp/wp-content/mu-plugins"
"${WP[@]}" plugin install "$MINUTES_ZIP" --activate
"${WP[@]}" plugin install "$PLUGIN_ZIP" --activate
"$APACHE" -f "$W/run/apache.conf" -k start
"$NGINX" -e "$W/logs/nginx-early.log" -p "$W/nginx" -c "$W/nginx/nginx.conf" >"$W/logs/nginx.out" 2>&1 &
PIDS+=($!)
for i in $(seq 1 50); do
	curl -s -o /dev/null "$SITE_A/" && curl -s -o /dev/null "$SITE_N/" && break
	sleep 0.2
done
pass "WordPress $("${WP[@]}" core version), theme $("${WP[@]}" theme list --status=active --field=name), importer $("${WP[@]}" plugin get $PL --field=version), BMLT Minutes $("${WP[@]}" plugin get bmlt-minutes --field=version); Apache $("$APACHE" -v | sed -n 's/Server version: Apache\/\([0-9.]*\).*/\1/p'), nginx $("$NGINX" -v 2>&1 | sed 's/.*nginx\///'), PHP $(php -r 'echo PHP_VERSION;')"
PAGE=$("${WP[@]}" post create --post_type=page --post_status=publish --post_title='Meeting Minutes' --post_name=meeting-minutes --post_content='<!-- wp:shortcode -->[bmlt_minutes]<!-- /wp:shortcode -->' --porcelain)
term() { "${WP[@]}" term list bmlt_committee --name="$1" --field=term_id; }
HI=$(term 'Hospitals &amp; Institutions')
POLICY=$(term Policy)
[ -n "$HI" ] && [ -n "$POLICY" ] || fail "BMLT Minutes' default committees: $("${WP[@]}" term list bmlt_committee --fields=term_id,name)"
"${WP[@]}" user create hi-manager hi@example.org --role=minutes_manager --user_pass=Hi-pass-1
"${WP[@]}" user meta update hi-manager _bmlt_minutes_committees "[$HI]" --format=json
pass "page \"Meeting Minutes\" with [bmlt_minutes]; a Minutes Manager limited to Hospitals & Institutions"

# Documents to upload.
F="$W/files"
mkdir -p "$F"
FX="$ROOT/tests/wordpress/fixtures"
cp "$FX/minutes.pdf" "$F/December ASC Minutes.pdf"
cp "$FX/minutes.pdf" "$F/January ASC Minutes.pdf"
cp "$FX/minutes.txt" "$F/H&I Sep Minutes.txt"
cp "$FX/motions.csv" "$F/motions.csv"
cp "$FX/photo.jpg" "$F/photo.jpg"
for i in 1 2 3 4 5 6; do cp "$FX/minutes.txt" "$F/Outreach $i.txt"; done
{ cat "$FX/minutes.pdf"; head -c $((7 * 1024 * 1024)) /dev/zero; } > "$F/big-7mb.pdf"
{ cat "$FX/minutes.pdf"; head -c $((12 * 1024 * 1024)) /dev/zero; } > "$F/huge-12mb.pdf"
YEAR=$(date +%Y)
minutes() { "${WP[@]}" post list --post_type=bmlt_minutes --post_status=any --orderby=ID --order=ASC --format=json --fields=ID,post_title,post_status,post_date "$@"; }
meta() { "${WP[@]}" post meta get "$1" "$2"; }

section "Browser: Single Import (Apache)"
python3 "$ROOT/bin/browser-e2e.py" single "$SITE_A" "$F" "$YEAR"
DEC=$(minutes | python3 -c 'import json,sys; print(next(p["ID"] for p in json.load(sys.stdin) if "December 31" in p["post_title"] and "Area" in p["post_title"]))')
[ "$("${WP[@]}" post get "$DEC" --field=post_status)" = publish ] || fail "minutes for 31 December $YEAR: status $("${WP[@]}" post get "$DEC" --field=post_status) (scheduled instead of published)"
[ "$(meta "$DEC" _bmlt_minutes_date)" = "$YEAR-12-31" ] || fail "meeting date meta"
ATT=$(meta "$DEC" _bmlt_minutes_attachment_id)
cmp -s "$("${WP[@]}" eval "echo get_attached_file( $ATT );")" "$F/December ASC Minutes.pdf" || fail "stored document differs from the upload"
pass "31 December $YEAR minutes published (not scheduled); meeting date and document stored where BMLT Minutes reads them, byte for byte"
[ "$(minutes | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')" = 2 ] || fail "records after single imports: $(minutes)"
[ -z "$("${WP[@]}" post list --post_type=attachment --format=ids --post_mime_type=image)" ] || fail "the JPEG reached the Media Library"
pass "two records; the refused JPEG is not in the Media Library"

section "Browser: Bulk Import (Apache)"
python3 "$ROOT/bin/browser-e2e.py" bulk "$SITE_A" "$F" 2018
minutes | python3 -c '
import json, sys
titles = sorted(p["post_title"] for p in json.load(sys.stdin))
need = ["January Area Minutes", "2018-01-08-Hospitals &amp; Institutions-HI-Sep-Minutes"]
missing = [t for t in need if t not in titles]
sys.exit("missing " + repr(missing) + " in " + repr(titles) if missing else 0)' || fail "bulk titles"
pass "titles: the typed one, and date-committee-file name for the other"

section "Browser: Bulk Import (nginx)"
python3 "$ROOT/bin/browser-e2e.py" bulk "$SITE_N" "$F" 2017

section "Browser: server limits (nginx)"
python3 "$ROOT/bin/browser-e2e.py" limits "$SITE_N" "$F"

section "Browser: committee scope (Apache)"
python3 "$ROOT/bin/browser-e2e.py" scoped "$SITE_A" "$POLICY"
[ -z "$(minutes | grep -i forged)" ] && [ "$("${WP[@]}" db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_value LIKE '%forged%'" --skip-column-names)" = 0 ] || fail "the forged import left a record"
uncategorized=$(minutes --format=ids | tr ' ' '\n' | while read -r id; do [ -z "$("${WP[@]}" post term list "$id" bmlt_committee --field=term_id)" ] && echo "$id" || true; done)
[ -z "$uncategorized" ] || fail "minutes without a committee: $uncategorized"
pass "no record without a committee"

section "Browser: the public minutes page (nginx)"
TITLES=$(minutes --post_status=publish | python3 -c 'import html, json, sys; print(json.dumps([html.unescape(p["post_title"]) for p in json.load(sys.stdin)][:12]))')
python3 "$ROOT/bin/browser-e2e.py" public "$SITE_N" "$TITLES"

section "Uninstall"
COUNT=$(minutes --format=count)
"${WP[@]}" option update bmai_meta_mapping '{"date":"","file":"","url":""}' --format=json
"${WP[@]}" plugin deactivate $PL
"${WP[@]}" plugin uninstall $PL
left=$("${WP[@]}" db query "SELECT option_name FROM wp_options WHERE option_name LIKE '%bmai%'" --skip-column-names)
[ -z "$left" ] || fail "left behind: $left"
[ "$(minutes --format=count)" = "$COUNT" ] || fail "minutes removed by uninstall"
pass "importer settings removed; all $COUNT imported minutes kept"

section "Logs"
# PHP's own warnings for the two requests over its limits, sent on purpose above, are expected.
EXPECTED_PHP='Maximum number of allowable file uploads has been exceeded|POST Content-Length of [0-9]+ bytes exceeds the limit'
[ "$(grep -cE "$EXPECTED_PHP" "$W/logs/php.log" 2>/dev/null)" = 2 ] || fail "PHP's warnings for the over-limit requests: $(grep -E "$EXPECTED_PHP" "$W/logs/php.log")"
if grep -v 'PHP Deprecated' "$W/logs/php.log" 2>/dev/null | grep -vE "$EXPECTED_PHP" | grep -q 'PHP '; then
	grep 'PHP ' "$W/logs/php.log" | grep -vE "$EXPECTED_PHP" | tail -20
	fail "PHP errors in the server log"
fi
if [ -s "$W/wp/wp-content/debug.log" ] && grep -q "$PL" "$W/wp/wp-content/debug.log"; then
	grep "$PL" "$W/wp/wp-content/debug.log" | tail -20
	fail "the plugin logged PHP notices or errors"
fi
pass "no PHP warnings or errors from the plugin"
grep -q "\" 5[0-9][0-9]$" "$W/logs/apache-access.log" && fail "a 5xx answer from Apache: $(grep '" 5' "$W/logs/apache-access.log" | head -3)"
awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | grep -q . && fail "a 5xx answer from nginx: $(awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | head -3)"
pass "no server errors"
echo
echo "End-to-end test passed."
