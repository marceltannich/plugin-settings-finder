<?php
/**
 * Plugin Name: Plugin Settings Finder (MU loader)
 * Description: Loads Plugin Settings Finder from its folder in mu-plugins. WordPress only loads PHP files at the top level of mu-plugins, never files in sub-folders.
 *
 * Copy this file to wp-content/mu-plugins/plugin-settings-finder-loader.php,
 * next to the plugin folder. `wp settings-finder install-mu` and the
 * "Move to must-use" link on the Plugins screen do that for you.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The usual folder name first, then renamed copies such as GitHub's "plugin-settings-finder-main".
$psf_loader_candidates = array_merge(
	array( __DIR__ . '/plugin-settings-finder/plugin-settings-finder.php' ),
	(array) glob( __DIR__ . '/plugin-settings-finder*/plugin-settings-finder.php' )
);
foreach ( $psf_loader_candidates as $psf_loader_file ) {
	if ( is_string( $psf_loader_file ) && is_file( $psf_loader_file ) ) {
		require_once $psf_loader_file;
		break;
	}
}
unset( $psf_loader_candidates, $psf_loader_file );
