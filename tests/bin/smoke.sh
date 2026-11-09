#!/usr/bin/env bash
#
# End-to-end checks against the test site, through WP-CLI and a real web
# server (php -S), for what PHPUnit cannot cover in-process:
#   - WP-CLI: rescan (loopback), list, --empty, --format=json, unknown plugin
#   - a second copy of the plugin and an incomplete copy activate without fatals
#   - install-mu, then back
#   - uninstall removes the plugin's data
#   - no PHP errors from this plugin in debug.log
#
# Needs a test site (composer test-site). Leaves it as it found it.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=tests/bin/lib.sh
source "$ROOT/tests/bin/lib.sh"
URL="$(wp option get siteurl 2> /dev/null)"
HOSTPORT="${URL#http://}"
case "$URL" in http://*:*) ;; *) echo "Unexpected site URL '$URL' (needs http://host:port)" >&2; exit 1 ;; esac
PLUGINS="$SITE/wp-content/plugins"
MU="$SITE/wp-content/mu-plugins"
LOG="$SITE/wp-content/debug.log"

pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1" >&2; exit 1; }
contains() { case "$1" in *"$2"*) return 0 ;; *) return 1 ;; esac; }

: > "$LOG"
php -S "$HOSTPORT" -t "$SITE" > /dev/null 2>&1 &
SERVER=$!
JAR="$(mktemp)"
cleanup() {
	kill "$SERVER" 2> /dev/null || true
	rm -f "$JAR"
	rm -rf "$PLUGINS/psf-copy" "$PLUGINS/psf-broken" "$MU/plugin-settings-finder" "$MU/plugin-settings-finder-main" "$MU/plugin-settings-finder-loader.php"
	wp plugin activate plugin-settings-finder --quiet 2> /dev/null || true
}
trap cleanup EXIT
for _ in $(seq 1 50); do curl -fs -o /dev/null "$URL/wp-login.php" && break; sleep 0.2; done

echo "WP-CLI"
out="$(wp settings-finder rescan 2>&1)"
contains "$out" "Index rebuilt" || fail "rescan via loopback: $out"
pass "rescan rebuilds the index through a wp-admin loopback request"

json="$(wp settings-finder list --format=json)"
php -r '
	$rows = json_decode( $argv[1], true );
	$find = function ( $plugin, $location ) use ( $rows ) {
		foreach ( $rows as $row ) {
			if ( $row["plugin"] === $plugin && $row["location"] === $location ) { return $row; }
		}
		fwrite( STDERR, "missing: $plugin / $location\n" ); exit( 1 );
	};
	$row = $find( "Example Settings", "Settings › Example Options" );
	if ( ! preg_match( "#/wp-admin/options-general\.php\?page=example-options$#", $row["url"] ) ) { fwrite( STDERR, "bad url {$row["url"]}\n" ); exit( 1 ); }
	$find( "Example Block", "No settings page found (blocks: Example Quote)" );
' "$json" || fail "list --format=json content"
pass "list --format=json has pages and absolute URLs"

empty="$(wp settings-finder list --empty --format=csv)"
contains "$empty" "Example Without Settings" || fail "list --empty"
contains "$empty" "Example Settings," && fail "list --empty lists a plugin that has pages"
pass "list --empty shows only plugins without pages"

if wp settings-finder list no-such-plugin > /dev/null 2>&1; then fail "unknown plugin should exit 1"; fi
pass "list <unknown> exits with an error"

echo "Second and incomplete copies"
rsync -a --exclude .git --exclude vendor --exclude tests --exclude dist "$ROOT/" "$PLUGINS/psf-copy/"
wp plugin activate psf-copy --quiet || fail "activating a second copy"
curl -fs -o /dev/null "$URL/" || fail "front end with two copies active"
wp plugin deactivate psf-copy --quiet
pass "a second copy activates and runs without a fatal error"

rsync -a --exclude .git --exclude vendor --exclude tests --exclude dist --exclude includes "$ROOT/" "$PLUGINS/psf-broken/"
wp plugin deactivate plugin-settings-finder --quiet
wp plugin activate psf-broken --quiet || fail "activating an incomplete copy"
curl -fs -o /dev/null "$URL/" || fail "front end with an incomplete copy"
wp plugin deactivate psf-broken --quiet
wp plugin activate plugin-settings-finder --quiet
pass "an incomplete copy activates without a fatal error"

echo "install-mu"
out="$(wp settings-finder install-mu 2>&1)"
contains "$out" "Installed as a must-use plugin" || fail "install-mu: $out"
[ -f "$MU/plugin-settings-finder-loader.php" ] || fail "loader written"
[ -d "$MU/plugin-settings-finder/includes" ] || fail "plugin copied"
[ ! -d "$MU/plugin-settings-finder/tests" ] && [ ! -d "$MU/plugin-settings-finder/vendor" ] || fail "dev folders not copied"
contains "$(wp plugin list --status=must-use --field=name)" "plugin-settings-finder-loader" || fail "listed as must-use"
contains "$(wp eval 'echo PSF_PLUGIN_DIR;')" "/mu-plugins/" || fail "runs from mu-plugins"
contains "$(wp settings-finder install-mu 2>&1)" "Already running from mu-plugins" || fail "second install-mu"
rm -rf "$MU/plugin-settings-finder" "$MU/plugin-settings-finder-loader.php"
wp plugin activate plugin-settings-finder --quiet
pass "install-mu copies runtime files, writes the loader and deactivates the regular copy"

echo "Move to must-use (Plugins screen link)"
curl -fs -c "$JAR" -b "$JAR" -o /dev/null "$URL/wp-login.php"
curl -fs -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=admin" --data-urlencode "pwd=psf-test-only" \
	--data-urlencode "testcookie=1" --data-urlencode "redirect_to=$URL/wp-admin/" "$URL/wp-login.php"
plugins_html="$(curl -fs -b "$JAR" "$URL/wp-admin/plugins.php")"
link="$(printf '%s' "$plugins_html" | php -r 'preg_match( "#href=\"([^\"]*action=psf_install_mu[^\"]*)\"#", stream_get_contents( STDIN ), $m ) && print( html_entity_decode( $m[1] ) );')"
[ -n "$link" ] || fail "Move to must-use link on the Plugins screen"
result="$(curl -fsL -b "$JAR" "$link")"
contains "$result" "now runs as a must-use plugin" || fail "success notice after the move"
[ -f "$MU/plugin-settings-finder-loader.php" ] && [ -d "$MU/plugin-settings-finder/includes" ] || fail "files in mu-plugins after the link"
contains "$(wp plugin list --status=inactive --field=name)" "plugin-settings-finder" || fail "regular copy deactivated by the link"
contains "$(curl -fs -b "$JAR" "$URL/wp-admin/plugins.php")" "action=psf_install_mu" && fail "link must disappear once running from mu-plugins"
rm -rf "$MU/plugin-settings-finder" "$MU/plugin-settings-finder-loader.php"
wp plugin activate plugin-settings-finder --quiet
pass "the link moves the plugin, shows a notice, and is gone afterwards"

rsync -a --exclude .git --exclude vendor --exclude tests --exclude dist "$ROOT/" "$MU/plugin-settings-finder-main/"
cp "$ROOT/extras/mu-loader.php" "$MU/plugin-settings-finder-loader.php"
wp plugin deactivate plugin-settings-finder --quiet
contains "$(wp eval 'echo PSF_PLUGIN_DIR;')" "/mu-plugins/plugin-settings-finder-main/" || fail "loader finds a renamed folder"
rm -rf "$MU/plugin-settings-finder-main" "$MU/plugin-settings-finder-loader.php"
wp plugin activate plugin-settings-finder --quiet
pass "the loader also finds a renamed folder (plugin-settings-finder-main)"

echo "Uninstall"
wp settings-finder rescan --quiet
wp option get psf_index > /dev/null || fail "index exists before uninstall"
wp plugin deactivate plugin-settings-finder --quiet
wp plugin uninstall plugin-settings-finder --skip-delete --quiet
if wp option get psf_index > /dev/null 2>&1; then fail "psf_index left after uninstall"; fi
wp plugin activate plugin-settings-finder --quiet
pass "uninstall removes the stored data"

echo "debug.log"
if grep -E "PHP (Fatal|Warning|Notice|Parse)" "$LOG" | grep -E "plugin-settings-finder|psf-copy|psf-broken"; then
	fail "PHP errors from this plugin in debug.log"
fi
pass "no PHP errors from this plugin"

echo "✓ Smoke tests passed"
