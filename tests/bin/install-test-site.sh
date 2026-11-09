#!/usr/bin/env bash
#
# Builds a disposable WordPress test site: SQLite (no MySQL needed), WP_DEBUG on,
# this plugin and the fixture plugins symlinked in and activated.
#
#   tests/bin/install-test-site.sh                 # site in /tmp/psf-test-site
#   WITH_WOO=1 tests/bin/install-test-site.sh      # also WooCommerce + the Woo fixture
#
# Environment:
#   PSF_TEST_SITE  target directory        (default /tmp/psf-test-site, deleted first)
#   PSF_TEST_URL   site URL                (default http://127.0.0.1:8899)
#   WP_VERSION     WordPress version       (default latest)
#   WITH_WOO       1 = install WooCommerce (default 0)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=tests/bin/lib.sh
source "$ROOT/tests/bin/lib.sh"
URL="${PSF_TEST_URL:-http://127.0.0.1:8899}"
WP_VERSION="${WP_VERSION:-latest}"
WITH_WOO="${WITH_WOO:-0}"
DL="$(mktemp -d)"
trap 'rm -rf "$DL"' EXIT

echo "→ WordPress ${WP_VERSION} into ${SITE}"
rm -rf "$SITE"
if [ "$WP_VERSION" = "latest" ]; then
	curl -fsSL -o "$DL/wp.zip" https://wordpress.org/latest.zip
else
	curl -fsSL -o "$DL/wp.zip" "https://wordpress.org/wordpress-${WP_VERSION}.zip"
fi
unzip -q "$DL/wp.zip" -d "$DL"
mv "$DL/wordpress" "$SITE"

echo "→ SQLite database drop-in"
curl -fsSL -o "$DL/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -q "$DL/sqlite.zip" -d "$SITE/wp-content/plugins"
SQLITE="$SITE/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${SQLITE}#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$SQLITE/db.copy" > "$SITE/wp-content/db.php"

cp "$SITE/wp-config-sample.php" "$SITE/wp-config.php"
php -r '
	$file = $argv[1];
	$config = file_get_contents( $file );
	$config = str_replace(
		"define( '\''WP_DEBUG'\'', false );",
		"define( '\''WP_DEBUG'\'', true );\ndefine( '\''WP_DEBUG_LOG'\'', true );\ndefine( '\''WP_DEBUG_DISPLAY'\'', false );",
		$config
	);
	file_put_contents( $file, $config );
' "$SITE/wp-config.php"

echo "→ Install at ${URL}"
wp core install --url="$URL" --title="PSF Tests" --admin_user=admin --admin_password=psf-test-only \
	--admin_email=admin@example.com --skip-email --quiet

echo "→ Plugin and fixtures"
mkdir -p "$SITE/wp-content/mu-plugins"
ln -s "$ROOT/tests/fixtures/mu-plugins/psf-test-env.php" "$SITE/wp-content/mu-plugins/psf-test-env.php"
ln -s "$ROOT" "$SITE/wp-content/plugins/plugin-settings-finder"
FIXTURES=()
for dir in "$ROOT"/tests/fixtures/plugins/psf-t-*; do
	name="$(basename "$dir")"
	ln -s "$dir" "$SITE/wp-content/plugins/$name"
	[ "$name" = "psf-t-woo-addon" ] || FIXTURES+=("$name")
done
wp plugin activate plugin-settings-finder "${FIXTURES[@]}" --quiet

if [ "$WITH_WOO" = "1" ]; then
	echo "→ WooCommerce"
	wp plugin install woocommerce --activate --quiet
	wp plugin activate psf-t-woo-addon --quiet
	wp transient delete _wc_activation_redirect --quiet || true
fi

wp plugin list --status=active --fields=name,version
echo "✓ Test site ready: ${SITE}"
