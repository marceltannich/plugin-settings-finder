<?php
/**
 * Plugin Name: Plugin Settings Finder (MU loader)
 * Description: Loads Plugin Settings Finder from its subfolder. WordPress only loads PHP files at the top level of mu-plugins.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_file( __DIR__ . '/plugin-settings-finder/plugin-settings-finder.php' ) ) {
	require_once __DIR__ . '/plugin-settings-finder/plugin-settings-finder.php';
}
