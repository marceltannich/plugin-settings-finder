<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'psf_index', 'psf_activations', 'psf_code_cache' ) as $psf_option ) {
	delete_option( $psf_option );
}

delete_metadata( 'user', 0, 'psf_sort', '', true );
