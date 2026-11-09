#!/usr/bin/env bash
# Shared helpers for the test scripts. Source it; do not run it.
#
# wp(): WP-CLI against the test site. When wp is the phar (shebang uses php),
# it runs through php so deprecations from WP-CLI's own vendor code on new PHP
# versions go to stderr instead of polluting stdout (JSON, CSV).

SITE="${PSF_TEST_SITE:-/tmp/psf-test-site}"
WP_BIN="$(command -v wp || true)"
if [ -z "$WP_BIN" ]; then
	echo "WP-CLI (wp) not found in PATH" >&2
	exit 1
fi

wp() {
	if head -n 1 "$WP_BIN" | grep -q php; then
		php -d error_reporting=24575 -d display_errors=stderr "$WP_BIN" --path="$SITE" "$@"
	else
		"$WP_BIN" --path="$SITE" "$@"
	fi
}
