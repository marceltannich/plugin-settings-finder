#!/usr/bin/env bash
#
# Runs the PHPUnit suite inside the test site as a wp-admin request.
#   tests/bin/run-phpunit.sh                    # all tests
#   tests/bin/run-phpunit.sh --filter Scanner   # PHPUnit arguments pass through
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=tests/bin/lib.sh
source "$ROOT/tests/bin/lib.sh"

if [ ! -f "$SITE/wp-config.php" ]; then
	echo "No test site in $SITE. Create it first: composer test-site" >&2
	exit 1
fi

# shellcheck disable=SC2016 # $_SERVER is PHP, not shell.
wp --user=admin \
	--exec='define( "WP_ADMIN", true ); $_SERVER["PHP_SELF"] = "/wp-admin/index.php";' \
	eval-file "$ROOT/tests/bin/phpunit.php" -- "$@"
