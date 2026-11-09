<?php
/**
 * Plugin Name:       Plugin Settings Finder
 * Description:       Adds an admin bar dropdown that lists every active plugin with direct links to its settings pages, wherever the plugin put them: top level, Settings, Tools, Media, WooCommerce menus, WooCommerce settings tabs and sections, hidden pages.
 * Version:           1.2.0
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

define( 'PSF_VERSION', '1.2.0' );
define( 'PSF_PLUGIN_FILE', __FILE__ );
define( 'PSF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PSF_PLUGIN_DIR . 'includes/class-psf-scanner.php';
require_once PSF_PLUGIN_DIR . 'includes/class-psf-admin-bar.php';

/**
 * Remember when each plugin was activated, so the newest one is listed first.
 *
 * @param string $plugin Plugin basename.
 */
function psf_record_activation( $plugin ) {
	$times            = get_option( 'psf_activations', array() );
	$times            = is_array( $times ) ? $times : array();
	$times[ $plugin ] = time();
	update_option( 'psf_activations', $times, false );
}
add_action( 'activated_plugin', 'psf_record_activation' );

PSF_Scanner::track_registrations();
( new PSF_Admin_Bar() )->register();
