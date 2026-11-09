<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'psf_index' );
delete_option( 'psf_activations' );
delete_option( 'psf_code_cache' );
delete_option( 'psf_usage_cache' );

delete_metadata( 'user', 0, 'psf_sort', '', true );
