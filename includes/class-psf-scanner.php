<?php
/**
 * Builds the plugin => admin pages index.
 *
 * Nothing here guesses from plugin names. Every page is attributed by asking
 * PHP where the code behind it lives: the menu page callback, the WooCommerce
 * settings class, the gateway class, the file that registered a post type.
 * The actual finding is done by detectors (includes/detectors/).
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the detectors over the active plugins and returns the index.
 */
final class PSF_Scanner {

	/**
	 * Detectors in run order. Page detectors first, then the code fallback
	 * (needs to know who has no pages), then usage (describes the rest).
	 *
	 * @return PSF_Detector[]
	 */
	public static function detectors() {
		$detectors = array(
			new PSF_Detector_Menu(),
			new PSF_Detector_WooCommerce(),
			new PSF_Detector_Action_Links(),
			new PSF_Detector_Code(),
			new PSF_Detector_Usage(),
		);

		/**
		 * Filters the detectors used to build the index.
		 *
		 * @param PSF_Detector[] $detectors Detectors in run order.
		 */
		$detectors = apply_filters( 'psf_detectors', $detectors );

		return array_values(
			array_filter(
				(array) $detectors,
				static function ( $detector ) {
					return $detector instanceof PSF_Detector;
				}
			)
		);
	}

	/**
	 * Build the index. Must run in wp-admin after the admin menu is built.
	 *
	 * @return array{time:int, sig:string, plugins:array}
	 */
	public function build() {
		$context = new PSF_Context( self::load_plugins() );

		foreach ( self::detectors() as $detector ) {
			$detector->detect( $context );
		}

		$plugins = array();
		foreach ( $context->plugins() as $basename => $plugin ) {
			$plugins[ $basename ] = array(
				'name'    => $plugin['name'],
				'entries' => self::sort_entries( $context->entries( $basename ) ),
				'usage'   => $context->usage( $basename ),
			);
		}

		return array(
			'time'    => time(),
			'sig'     => self::signature(),
			'plugins' => $plugins,
		);
	}

	/**
	 * Real menu and WooCommerce pages first, settings-looking pages first
	 * within each group, so clicking the plugin name lands on its settings.
	 *
	 * @param array $entries Entries with a `seq` insertion counter.
	 * @return array Sorted entries without `seq`.
	 */
	public static function sort_entries( array $entries ) {
		$key = static function ( $entry ) {
			return array(
				PSF_Context::RANKS[ $entry['type'] ] <= PSF_Context::RANKS['woo'] ? 0 : 1,
				preg_match( '/setting|option|einstellung|config|setup/i', $entry['label'] . ' ' . $entry['url'] ) ? 0 : 1,
				PSF_Context::RANKS[ $entry['type'] ],
				isset( $entry['seq'] ) ? $entry['seq'] : 0,
			);
		};
		usort(
			$entries,
			static function ( $a, $b ) use ( $key ) {
				return $key( $a ) <=> $key( $b );
			}
		);
		foreach ( $entries as &$entry ) {
			unset( $entry['seq'] );
		}
		unset( $entry );
		return $entries;
	}

	/**
	 * Signature of the active plugin set; a change triggers a rebuild.
	 *
	 * @return string
	 */
	public static function signature() {
		$all   = self::all_plugins();
		$parts = array( PSF_VERSION );
		foreach ( self::active_basenames() as $basename ) {
			$parts[] = $basename . '@' . ( isset( $all[ $basename ]['Version'] ) ? $all[ $basename ]['Version'] : '' );
		}
		sort( $parts );
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Active plugins (except this one) and where they live on disk, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function load_plugins() {
		$all     = self::all_plugins();
		$self    = plugin_basename( PSF_PLUGIN_FILE );
		$plugins = array();

		foreach ( self::active_basenames() as $basename ) {
			if ( $basename === $self || ! isset( $all[ $basename ] ) ) {
				continue;
			}
			$is_dir = '.' !== dirname( $basename );
			$path   = wp_normalize_path( WP_PLUGIN_DIR . '/' . ( $is_dir ? dirname( $basename ) . '/' : $basename ) );
			$real   = realpath( $is_dir ? WP_PLUGIN_DIR . '/' . dirname( $basename ) : WP_PLUGIN_DIR . '/' . $basename );

			$plugins[ $basename ] = array(
				'name'    => wp_strip_all_tags( $all[ $basename ]['Name'] ),
				'version' => $all[ $basename ]['Version'],
				'data'    => $all[ $basename ],
				'is_dir'  => $is_dir,
				'paths'   => array_values(
					array_unique(
						array_filter(
							array(
								$path,
								$real ? wp_normalize_path( $real ) . ( $is_dir ? '/' : '' ) : '',
							)
						)
					)
				),
			);
		}

		uasort(
			$plugins,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		return $plugins;
	}

	/**
	 * Installed plugin headers.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function all_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}

	/**
	 * Active plugin basenames, including network-activated ones.
	 *
	 * @return string[]
	 */
	private static function active_basenames() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_filter( $active, 'is_string' ) ) );
	}
}
