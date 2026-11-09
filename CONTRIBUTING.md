# Contributing

Everything you need to change, test and release Plugin Settings Finder.

## Requirements

* PHP 7.4 or newer (CI runs 7.4 to 8.4), with the `sqlite3`, `pdo_sqlite` and `zip` extensions
* [Composer](https://getcomposer.org/) and [WP-CLI](https://wp-cli.org/)
* `curl`, `unzip`, `zip`, `rsync`

No MySQL, Docker or local WordPress install needed: the tests build their own throwaway site with SQLite.

## Setup

```bash
git clone https://github.com/marceltannich/plugin-settings-finder.git
cd plugin-settings-finder
composer install
composer test-site            # or: WITH_WOO=1 composer test-site
```

`composer test-site` creates `/tmp/psf-test-site` (change with `PSF_TEST_SITE=…`): latest WordPress, SQLite, `WP_DEBUG` on, this plugin and the fixture plugins from `tests/fixtures/plugins/` symlinked in and active. `WITH_WOO=1` adds WooCommerce and the WooCommerce fixture. Run it again any time for a clean site.

> Work in a clone, not in a copy that a live site loads (for example a `mu-plugins` folder). `composer install` creates `vendor/`, and half-finished edits load immediately.

## Checks

| Command | What it does |
| --- | --- |
| `composer lint` | `php -l` on every PHP file |
| `composer phpcs` | WordPress Coding Standards + PHP 7.4 compatibility (`composer phpcbf` fixes what it can) |
| `composer test` | PHPUnit inside the test site, as a wp-admin request. Arguments pass through: `composer test -- --filter Scanner` |
| `composer smoke` | End to end through WP-CLI, `curl` and `php -S`: `rescan`, `list`, second and incomplete copies, `install-mu`, the **Move to must-use** link (logged in), a renamed mu-plugins folder, uninstall, no PHP errors in `debug.log` |
| `composer build` | `dist/plugin-settings-finder.zip` with runtime files only |
| `composer pot` | Regenerate `languages/plugin-settings-finder.pot` |

CI (`.github/workflows/ci.yml`) runs all of them on every push and pull request, plus [Plugin Check](https://wordpress.org/plugins/plugin-check/) on the built ZIP. Plugin Check errors fail the build; the warning about "plugin" in the name is expected.

### Why the tests run through WP-CLI

The detectors read the admin menu, which only exists inside a wp-admin request. `tests/bin/run-phpunit.sh` starts WP-CLI with `WP_ADMIN` defined before WordPress loads, `tests/bin/phpunit.php` builds the menu like `wp-admin/admin.php` does and then hands over to PHPUnit. (WP-CLI's own `--context=admin` evaluates `admin.php` inside a method, so the menu ends up in local variables and the detectors see nothing.)

## How the code is organised

```
plugin-settings-finder.php        Loader: guards, file list, hooks
includes/
  class-psf-admin-bar.php         The dropdown; when to rebuild; assets
  class-psf-scanner.php           Runs the detectors, sorts, builds the index
  class-psf-context.php           Shared state of one build + attribution helpers
  class-psf-files.php             Bounded reads of a plugin's files
  class-psf-registrations.php     Which file registered each post type / taxonomy
  class-psf-installer.php         Move to must-use (Plugins screen link and install-mu)
  class-psf-cli.php               wp settings-finder list | rescan | install-mu
  interface-psf-detector.php
  detectors/
    class-psf-detector-menu.php          Admin menu + hidden pages
    class-psf-detector-woocommerce.php   WooCommerce tabs, sections, gateways, …
    class-psf-detector-action-links.php  Links on the Plugins screen
    class-psf-detector-code.php          Fallback: slugs found in the plugin's code
    class-psf-detector-usage.php         No pages? Blocks, shortcodes, widgets, readme
assets/                           Admin bar CSS and filter JS
tests/
  fixtures/plugins/               One small plugin per case (see its README)
  phpunit/                        HelpersTest, ScannerTest, AdminBarTest
  bin/                            Test site, PHPUnit runner, smoke tests
```

**Attribution, not guessing.** A page belongs to the plugin whose file contains the code that renders it: the menu callback, the WooCommerce settings class, the gateway class, the file that called `register_post_type()`. `PSF_Context::owner_of_file()` maps a file to an active plugin, including symlinked plugins (PHP reports their real path).

**Detectors run in order** and share one `PSF_Context`. Page detectors first, then the code fallback (only for plugins nobody found pages for), then usage (only for plugins that still have none). Add your own with the `psf_detectors` filter; each implements `PSF_Detector::detect( PSF_Context $context )` and calls `$context->add_entry()`. When two detectors find the same screen, the lower rank in `PSF_Context::RANKS` wins.

**When the index is rebuilt.** It is stored in the `psf_index` option. On an admin screen it is rebuilt immediately when there is none yet, after **Rescan now**, or when the set of active plugins changed (so a plugin you just activated is in the list right away). When it is merely older than ten minutes, the page renders with the stored index and the rebuild runs on `shutdown`, after the response was sent. File-based results (code scan, block.json, readme) are cached per plugin version in `psf_code_cache` and `psf_usage_cache`.

**One caveat.** To learn which WooCommerce tabs and sections a plugin adds, the WooCommerce detector calls that plugin's own filter callback with an empty array (`PSF_Context::probe()`), with output buffered and exceptions caught. That is code the site already runs on every WooCommerce settings screen, but a badly written callback could have side effects.

## Adding a case

1. Add a fixture plugin in `tests/fixtures/plugins/psf-t-<case>/` (and a line in its README).
2. Add a test in `tests/phpunit/ScannerTest.php` that fails.
3. Make it pass in the detector that owns the case, or add a new detector.
4. `composer test-site && composer test && composer smoke && composer phpcs`.

## Releasing

1. Bump the version in `plugin-settings-finder.php` (header and `PSF_VERSION`) and `Stable tag` in `readme.txt`; add a changelog entry there.
2. `composer pot` if strings changed.
3. Push to `main` and wait for CI to pass.
4. `composer build`, then create the release and attach the ZIP **with exactly this file name**, because the README links to `releases/latest/download/plugin-settings-finder.zip`:

```bash
gh release create v1.5.1 dist/plugin-settings-finder.zip --title "Plugin Settings Finder 1.5.1" --generate-notes
```
