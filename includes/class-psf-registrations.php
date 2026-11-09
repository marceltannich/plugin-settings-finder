<?php
/**
 * Remembers which plugin file registered each post type and taxonomy.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post type and taxonomy screens have no callback to reflect on, so the
 * registering file is taken from the call stack at registration time.
 */
final class PSF_Registrations {

	/**
	 * Registering files: kind => name => absolute path.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $files = array(
		'post_type' => array(),
		'taxonomy'  => array(),
	);

	/**
	 * Start listening. Only on admin requests, where the index is built.
	 */
	public static function track() {
		if ( ! is_admin() ) {
			return;
		}
		add_action(
			'registered_post_type',
			static function ( $name ) {
				self::remember( 'post_type', (string) $name );
			}
		);
		add_action(
			'registered_taxonomy',
			static function ( $name ) {
				self::remember( 'taxonomy', (string) $name );
			}
		);
	}

	/**
	 * File that registered a post type or taxonomy.
	 *
	 * @param string $kind post_type|taxonomy.
	 * @param string $name Object name.
	 * @return string|null
	 */
	public static function file( $kind, $name ) {
		return isset( self::$files[ $kind ][ $name ] ) ? self::$files[ $kind ][ $name ] : null;
	}

	/**
	 * Store the first file in the call stack that is not WordPress core and
	 * not this plugin's own code. Which plugin it belongs to is decided later
	 * by PSF_Context::owner_of_file(), which also understands symlinked
	 * plugins (PHP reports their real path, outside wp-content/plugins).
	 *
	 * @param string $kind post_type|taxonomy.
	 * @param string $name Object name.
	 */
	private static function remember( $kind, $name ) {
		$skip = array(
			wp_normalize_path( ABSPATH . WPINC ) . '/',
			wp_normalize_path( ABSPATH . 'wp-admin' ) . '/',
			wp_normalize_path( PSF_PLUGIN_DIR . 'includes' ) . '/',
			wp_normalize_path( PSF_PLUGIN_FILE ),
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attribution only, never output.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			foreach ( $skip as $prefix ) {
				if ( 0 === strpos( $file, $prefix ) ) {
					continue 2;
				}
			}
			self::$files[ $kind ][ $name ] = $file;
			return;
		}
	}
}
