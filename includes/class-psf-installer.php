<?php
/**
 * Moves the plugin into mu-plugins.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress only loads PHP files at the top level of mu-plugins, never files
 * in sub-folders, so a must-use install needs the plugin folder plus a loader
 * file next to it. This does both, from the running regular copy, and
 * deactivates that copy. Used by the "Move to must-use" link on the Plugins
 * screen and by `wp settings-finder install-mu`.
 */
final class PSF_Installer {

	/**
	 * Folders a must-use install never needs.
	 */
	const SKIP = array( '.git', '.github', 'tests', 'vendor', 'node_modules', 'dist', 'bin', 'docs' );

	/**
	 * Folder name inside mu-plugins.
	 */
	const FOLDER = 'plugin-settings-finder';

	/**
	 * Loader file name inside mu-plugins.
	 */
	const LOADER = 'plugin-settings-finder-loader.php';

	/**
	 * Admin action name for the "Move to must-use" link.
	 */
	const ACTION = 'psf_install_mu';

	/**
	 * Hook the "Move to must-use" link and its handler.
	 */
	public static function register() {
		// The notice is shown by the copy that runs after the move, i.e. the must-use one.
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		if ( self::is_must_use() ) {
			return;
		}
		add_filter( 'plugin_action_links_' . plugin_basename( PSF_PLUGIN_FILE ), array( __CLASS__, 'action_link' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Add "Move to must-use" to this plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Row links.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		if ( ! current_user_can( 'activate_plugins' ) || ( is_multisite() && ! current_user_can( 'manage_network_plugins' ) ) ) {
			return $links;
		}
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		$links[] = sprintf(
			'<a href="%1$s" title="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr__( 'Copy this plugin to wp-content/mu-plugins so it is always active, then deactivate this copy.', 'plugin-settings-finder' ),
			esc_html__( 'Move to must-use', 'plugin-settings-finder' )
		);
		return $links;
	}

	/**
	 * Run the move from the Plugins screen link.
	 */
	public static function handle() {
		if ( ! current_user_can( 'activate_plugins' ) || ( is_multisite() && ! current_user_can( 'manage_network_plugins' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage plugins.', 'plugin-settings-finder' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$result = self::install( true );
		if ( is_wp_error( $result ) ) {
			set_transient( self::ACTION . '_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			$args = array( 'psf_mu' => 'error' );
		} else {
			$args = array(
				'psf_mu'        => 'done',
				'plugin_status' => 'mustuse',
			);
		}
		wp_safe_redirect( add_query_arg( $args, self_admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * Result notice on the Plugins screen.
	 */
	public static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, set by our own redirect.
		$state = isset( $_GET['psf_mu'] ) ? sanitize_key( wp_unslash( $_GET['psf_mu'] ) ) : '';
		if ( 'done' === $state ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Plugin Settings Finder now runs as a must-use plugin. You can delete the regular copy under Plugins.', 'plugin-settings-finder' )
			);
			return;
		}
		$key   = self::ACTION . '_error_' . get_current_user_id();
		$error = 'error' === $state ? get_transient( $key ) : false;
		if ( $error ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html__( 'Could not move Plugin Settings Finder to mu-plugins:', 'plugin-settings-finder' ),
				esc_html( $error )
			);
		}
	}

	/**
	 * Whether this copy is the one running from mu-plugins.
	 *
	 * @return bool
	 */
	public static function is_must_use() {
		return 0 === strpos( wp_normalize_path( PSF_PLUGIN_DIR ), trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) );
	}

	/**
	 * Copy into mu-plugins, write the loader, deactivate the regular copy.
	 *
	 * @param bool $force Overwrite an existing copy in mu-plugins.
	 * @return string[]|WP_Error Log lines on success.
	 */
	public static function install( $force = false ) {
		$source = untrailingslashit( PSF_PLUGIN_DIR );
		$mu_dir = untrailingslashit( WPMU_PLUGIN_DIR );
		$target = $mu_dir . '/' . self::FOLDER;
		$loader = $mu_dir . '/' . self::LOADER;
		$log    = array();

		if ( self::is_must_use() ) {
			return new WP_Error( 'psf_already_mu', __( 'Plugin Settings Finder already runs as a must-use plugin.', 'plugin-settings-finder' ) );
		}
		if ( is_dir( $target . '/.git' ) ) {
			/* translators: %s: folder path. */
			return new WP_Error( 'psf_git', sprintf( __( '%s is a Git checkout. Update it with git instead.', 'plugin-settings-finder' ), $target ) );
		}
		if ( file_exists( $target ) && ! $force ) {
			/* translators: %s: folder path. */
			return new WP_Error( 'psf_exists', sprintf( __( '%s already exists.', 'plugin-settings-finder' ), $target ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( 'direct' !== get_filesystem_method( array(), $mu_dir ) || ! WP_Filesystem() ) {
			return new WP_Error( 'psf_filesystem', __( 'WordPress cannot write to wp-content/mu-plugins directly on this server. Copy the files by hand (see the README).', 'plugin-settings-finder' ) );
		}
		global $wp_filesystem;

		if ( ! $wp_filesystem->is_dir( $mu_dir ) && ! $wp_filesystem->mkdir( $mu_dir, FS_CHMOD_DIR ) ) {
			/* translators: %s: folder path. */
			return new WP_Error( 'psf_mkdir', sprintf( __( 'Could not create %s.', 'plugin-settings-finder' ), $mu_dir ) );
		}
		if ( $wp_filesystem->is_dir( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		if ( ! $wp_filesystem->mkdir( $target, FS_CHMOD_DIR ) ) {
			/* translators: %s: folder path. */
			return new WP_Error( 'psf_mkdir', sprintf( __( 'Could not create %s.', 'plugin-settings-finder' ), $target ) );
		}
		$copied = copy_dir( $source, $target, self::SKIP );
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}
		if ( ! $wp_filesystem->copy( $source . '/extras/mu-loader.php', $loader, true, FS_CHMOD_FILE ) ) {
			/* translators: %s: file path. */
			return new WP_Error( 'psf_loader', sprintf( __( 'Could not write %s.', 'plugin-settings-finder' ), $loader ) );
		}
		/* translators: %s: folder path. */
		$log[] = sprintf( __( 'Copied to %s', 'plugin-settings-finder' ), $target );
		/* translators: %s: file path. */
		$log[] = sprintf( __( 'Wrote loader %s', 'plugin-settings-finder' ), $loader );

		$basename = plugin_basename( PSF_PLUGIN_FILE );
		$network  = is_multisite() && is_plugin_active_for_network( $basename );
		if ( $network || is_plugin_active( $basename ) ) {
			deactivate_plugins( $basename, true, $network );
			/* translators: %s: plugin basename. */
			$log[] = sprintf( __( 'Deactivated the regular copy (%s).', 'plugin-settings-finder' ), $basename );
		}
		return $log;
	}
}
