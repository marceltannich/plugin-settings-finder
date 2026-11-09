<?php
/**
 * Plugin Name:       Plugin Settings Finder
 * Description:       Adds an admin bar dropdown that lists every active plugin with direct links to its settings pages, wherever the plugin put them: top level, Settings, Tools, Media, WooCommerce menus, WooCommerce settings tabs and sections, hidden pages.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Marcel Tannich
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugin-settings-finder
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Already loaded, e.g. as a must-use plugin and as a regular plugin at once.
if ( defined( 'PSF_VERSION' ) ) {
	return;
}

// Every file the plugin needs, in load order.
$psf_files = array(
	'includes/interface-psf-detector.php',
	'includes/class-psf-context.php',
	'includes/class-psf-files.php',
	'includes/class-psf-registrations.php',
	'includes/detectors/class-psf-detector-menu.php',
	'includes/detectors/class-psf-detector-woocommerce.php',
	'includes/detectors/class-psf-detector-action-links.php',
	'includes/detectors/class-psf-detector-code.php',
	'includes/detectors/class-psf-detector-usage.php',
	'includes/class-psf-scanner.php',
	'includes/class-psf-admin-bar.php',
);

// Refuse to load from an incomplete copy instead of failing with a fatal error.
$psf_missing = array();
foreach ( $psf_files as $psf_file ) {
	if ( ! is_readable( __DIR__ . '/' . $psf_file ) ) {
		$psf_missing[] = $psf_file;
	}
}
if ( $psf_missing ) {
	add_action(
		'admin_notices',
		static function () use ( $psf_missing ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of missing files. */
						__( 'Plugin Settings Finder is incomplete and has not been loaded. Missing files: %s. Please reinstall the plugin.', 'plugin-settings-finder' ),
						implode( ', ', $psf_missing )
					)
				)
			);
		}
	);
	return;
}

define( 'PSF_VERSION', '1.5.0' );
define( 'PSF_PLUGIN_FILE', __FILE__ );
define( 'PSF_PLUGIN_DIR', __DIR__ . '/' );

foreach ( $psf_files as $psf_file ) {
	require_once __DIR__ . '/' . $psf_file;
}
unset( $psf_files, $psf_missing, $psf_file );

if ( defined( 'WP_CLI' ) && WP_CLI && is_readable( __DIR__ . '/includes/class-psf-cli.php' ) ) {
	require_once __DIR__ . '/includes/class-psf-cli.php';
}

// Remember when each plugin was activated, so the newest one can be listed first.
// A closure, not a named function: PHP declares top-level functions at compile
// time, so a second copy of this file would fatal despite the guard above.
add_action(
	'activated_plugin',
	static function ( $plugin ) {
		$times            = get_option( 'psf_activations', array() );
		$times            = is_array( $times ) ? $times : array();
		$times[ $plugin ] = time();
		update_option( 'psf_activations', $times, false );
	}
);

// Translations from languages/, wherever the plugin lives (plugins or mu-plugins).
add_action(
	'init',
	static function () {
		$mofile = __DIR__ . '/languages/plugin-settings-finder-' . determine_locale() . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'plugin-settings-finder', $mofile );
		}
	}
);

PSF_Registrations::track();
( new PSF_Admin_Bar() )->register();
