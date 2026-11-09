# Test fixtures

Small plugins, one per case the detectors must handle. `tests/bin/install-test-site.sh` symlinks them into the test site and activates them. They are never part of the release ZIP.

| Plugin | Case |
| --- | --- |
| `psf-t-settings` | Page under Settings, row "Settings" link, external link ignored, shared menu helper |
| `psf-t-framework-user` | Menu registered through another plugin's helper; found only by the code scan |
| `psf-t-media` | Page under Media |
| `psf-t-tools` | Page under Tools, callback is a class method |
| `psf-t-top` | Top-level menu with count bubble, plus submenu |
| `psf-t-hidden` | Hidden page (no visible parent) |
| `psf-t-cpt` | Custom post type screens |
| `psf-t-none` | No pages; readme says "nothing to configure" |
| `psf-t-block` | No pages; block from `block.json` |
| `psf-t-shortcode` | No pages; two shortcodes |
| `psf-t-widget` | No pages; classic widget |
| `psf-t-woo-addon` | WooCommerce tab, section and gateway (only with WooCommerce) |
