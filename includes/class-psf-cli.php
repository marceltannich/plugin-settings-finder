<?php
/**
 * WP-CLI commands.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find plugin settings pages from the command line.
 *
 * The page index needs the WordPress admin menu, which only exists inside
 * wp-admin. `rescan` therefore loads one wp-admin page of this site to rebuild
 * it; `list` shows the current index.
 */
final class PSF_CLI {

	/**
	 * Lists the settings pages found for each active plugin.
	 *
	 * ## OPTIONS
	 *
	 * [<plugin>]
	 * : Only this plugin. Folder name or basename, e.g. `akismet` or `akismet/akismet.php`.
	 *
	 * [--empty]
	 * : Only plugins without a settings page.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp settings-finder list
	 *     wp settings-finder list woocommerce-gateway-example
	 *     wp settings-finder list --empty
	 *     wp settings-finder list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		$index = get_option( PSF_Admin_Bar::OPTION );
		if ( ! is_array( $index ) || empty( $index['plugins'] ) ) {
			WP_CLI::error( 'No index yet. Run `wp settings-finder rescan` or open any wp-admin screen once as an administrator.' );
		}

		$filter = isset( $args[0] ) ? (string) $args[0] : '';
		$rows   = array();
		foreach ( $index['plugins'] as $basename => $plugin ) {
			if ( '' !== $filter && $basename !== $filter && strtok( $basename, '/' ) !== $filter ) {
				continue;
			}
			if ( ! empty( $assoc_args['empty'] ) && ! empty( $plugin['entries'] ) ) {
				continue;
			}
			if ( empty( $plugin['entries'] ) ) {
				$rows[] = array(
					'plugin'   => $plugin['name'],
					'location' => 'No settings page found' . $this->usage_summary( isset( $plugin['usage'] ) ? (array) $plugin['usage'] : array() ),
					'url'      => '',
					'source'   => '',
				);
				continue;
			}
			foreach ( $plugin['entries'] as $entry ) {
				$rows[] = array(
					'plugin'   => $plugin['name'],
					'location' => $entry['label'],
					'url'      => admin_url( $entry['url'] ),
					'source'   => $entry['type'],
				);
			}
		}

		if ( '' !== $filter && ! $rows ) {
			WP_CLI::error( sprintf( 'No active plugin "%s" in the index.', $filter ) );
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'plugin', 'location', 'url', 'source' ) );

		if ( 'table' === $assoc_args['format'] ) {
			WP_CLI::log( sprintf( 'Index built %s ago. Refresh with `wp settings-finder rescan`.', human_time_diff( (int) $index['time'] ) ) );
		}
	}

	/**
	 * Rebuilds the index.
	 *
	 * The admin menu only exists inside wp-admin, so this loads one wp-admin
	 * page of this site (a loopback request) as an administrator, using a login
	 * session that is created for the request and destroyed right after.
	 * If the site cannot reach itself (local DNS, firewall, basic auth), the
	 * index is cleared instead and rebuilt on the next wp-admin page view.
	 *
	 * ## OPTIONS
	 *
	 * [--clear-only]
	 * : Only clear the index, do not make a loopback request.
	 *
	 * ## EXAMPLES
	 *
	 *     wp settings-finder rescan
	 *     wp settings-finder rescan --user=admin
	 *     wp settings-finder rescan --clear-only
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rescan( $args, $assoc_args ) {
		delete_option( PSF_Admin_Bar::OPTION );
		delete_option( 'psf_code_cache' );
		delete_option( 'psf_usage_cache' );

		if ( ! empty( $assoc_args['clear-only'] ) ) {
			WP_CLI::success( 'Index cleared. It is rebuilt the next time an administrator opens any wp-admin screen.' );
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'activate_plugins' ) ) {
			$admins  = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			$user_id = $admins ? (int) $admins[0] : 0;
		}
		if ( ! $user_id ) {
			WP_CLI::warning( 'No administrator found. Index cleared; open wp-admin once to rebuild it.' );
			return;
		}

		$expires = time() + 120;
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$token   = $manager->create( $expires );
		$secure  = 'https' === wp_parse_url( admin_url(), PHP_URL_SCHEME );
		$cookies = array(
			new WP_Http_Cookie(
				array(
					'name'  => $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE,
					'value' => wp_generate_auth_cookie( $user_id, $expires, $secure ? 'secure_auth' : 'auth', $token ),
				)
			),
			new WP_Http_Cookie(
				array(
					'name'  => LOGGED_IN_COOKIE,
					'value' => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token ),
				)
			),
		);

		$response = wp_remote_get(
			admin_url( 'index.php' ),
			array(
				'cookies'   => $cookies,
				'timeout'   => 60,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		$manager->destroy( $token );

		// The index was written by the other request; drop this process's cached view.
		wp_cache_delete( PSF_Admin_Bar::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$index = get_option( PSF_Admin_Bar::OPTION );

		if ( is_array( $index ) && isset( $index['plugins'] ) ) {
			WP_CLI::success( sprintf( 'Index rebuilt: %d plugins. Show it with `wp settings-finder list`.', count( $index['plugins'] ) ) );
			return;
		}

		$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
		WP_CLI::warning( sprintf( 'Could not load wp-admin from the command line (%s). Index cleared; open wp-admin once in a browser to rebuild it.', $reason ) );
	}

	/**
	 * Moves the plugin into mu-plugins so it is always on and cannot be deactivated.
	 *
	 * Copies the plugin folder to wp-content/mu-plugins/plugin-settings-finder/,
	 * writes the loader file wp-content/mu-plugins/plugin-settings-finder-loader.php
	 * (WordPress only loads PHP files at the top level of mu-plugins) and
	 * deactivates the regular copy. Delete the regular copy afterwards with
	 * `wp plugin delete plugin-settings-finder` if you like.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite an existing copy in mu-plugins.
	 *
	 * ## EXAMPLES
	 *
	 *     wp settings-finder install-mu
	 *     wp plugin delete plugin-settings-finder
	 *
	 * @subcommand install-mu
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function install_mu( $args, $assoc_args ) {
		$source = untrailingslashit( PSF_PLUGIN_DIR );
		$mu_dir = untrailingslashit( WPMU_PLUGIN_DIR );
		$target = $mu_dir . '/plugin-settings-finder';
		$loader = $mu_dir . '/plugin-settings-finder-loader.php';

		if ( wp_normalize_path( $source ) === wp_normalize_path( $target ) ) {
			WP_CLI::success( 'Already running from mu-plugins. Nothing to do.' );
			return;
		}
		if ( file_exists( $target ) && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( "{$target} already exists. Use --force to overwrite it." );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! WP_Filesystem() ) {
			WP_CLI::error( 'Could not access the filesystem.' );
		}
		global $wp_filesystem;

		if ( ! $wp_filesystem->is_dir( $mu_dir ) && ! $wp_filesystem->mkdir( $mu_dir, FS_CHMOD_DIR ) ) {
			WP_CLI::error( "Could not create {$mu_dir}." );
		}
		if ( $wp_filesystem->is_dir( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		if ( ! $wp_filesystem->mkdir( $target, FS_CHMOD_DIR ) ) {
			WP_CLI::error( "Could not create {$target}." );
		}
		$copied = copy_dir( $source, $target, array( '.git' ) );
		if ( is_wp_error( $copied ) ) {
			WP_CLI::error( $copied->get_error_message() );
		}
		if ( ! $wp_filesystem->copy( $source . '/extras/mu-loader.php', $loader, true, FS_CHMOD_FILE ) ) {
			WP_CLI::error( "Could not write {$loader}." );
		}
		WP_CLI::log( "Copied to {$target}" );
		WP_CLI::log( "Wrote loader {$loader}" );

		$basename = plugin_basename( PSF_PLUGIN_FILE );
		if ( is_plugin_active( $basename ) || is_plugin_active_for_network( $basename ) ) {
			deactivate_plugins( $basename, true, is_plugin_active_for_network( $basename ) );
			WP_CLI::log( "Deactivated the regular copy ({$basename})." );
		}

		WP_CLI::success( 'Installed as a must-use plugin. Remove the regular copy with: wp plugin delete ' . dirname( $basename ) );
	}

	/**
	 * Short "what it adds instead" text for plugins without settings.
	 *
	 * @param array $usage Usage record.
	 * @return string
	 */
	private function usage_summary( array $usage ) {
		$parts = array();
		foreach ( array( 'blocks' => 'blocks', 'shortcodes' => 'shortcodes', 'widgets' => 'widgets' ) as $key => $label ) {
			if ( ! empty( $usage[ $key ] ) ) {
				$parts[] = $label . ': ' . implode( ', ', $usage[ $key ] );
			}
		}
		return $parts ? ' (' . implode( '; ', $parts ) . ')' : '';
	}
}

WP_CLI::add_command(
	'settings-finder',
	'PSF_CLI',
	array( 'shortdesc' => 'Find plugin settings pages from the command line.' )
);
