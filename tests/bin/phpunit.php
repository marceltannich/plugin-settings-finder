<?php
/**
 * Runs PHPUnit inside a wp-admin request.
 *
 * Started by `composer test`, i.e.
 *   wp --path=<site> --user=admin --exec="define('WP_ADMIN', true);" eval-file tests/bin/phpunit.php [phpunit args]
 *
 * WP_ADMIN is defined before WordPress loads, so plugins see is_admin() while
 * they boot. This file then builds the admin menu the way wp-admin/admin.php
 * does (the detectors read it) and hands over to PHPUnit.
 *
 * Not WP-CLI's --context=admin: it evaluates admin.php inside a method, so the
 * menu ends up in local variables instead of the globals.
 *
 * @package PluginSettingsFinder
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Recreating wp-admin's own globals.

if ( ! defined( 'ABSPATH' ) || ! is_admin() ) {
	fwrite( STDERR, "Run via: composer test\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

// wp-admin/menu.php writes these as globals, but eval-file runs in a function scope.
global $menu, $submenu, $_wp_menu_nopriv, $_wp_submenu_nopriv, $_registered_pages, $_parent_pages,
	$admin_page_hooks, $_wp_real_parent_file, $pagenow, $typenow, $taxnow, $plugin_page, $parent_file, $submenu_file, $self, $compat, $hook_suffix, $menu_order, $default_menu_order;

$pagenow     = 'index.php';
$hook_suffix = 'index.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/menu.php';
do_action( 'admin_init' );

$root = dirname( __DIR__, 2 );
require_once $root . '/vendor/autoload.php';

// WP-CLI hands extra eval-file arguments over as $args, e.g. `-- --filter Scanner`.
$psf_phpunit_args = isset( $args ) ? (array) $args : array();
$_SERVER['argv']  = array_merge( array( 'phpunit', '--configuration', $root . '/phpunit.xml.dist' ), $psf_phpunit_args );

$status = PHPUnit\TextUI\Command::main( false );
WP_CLI::halt( (int) $status );
