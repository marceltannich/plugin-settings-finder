<?php
/**
 * Finds the admin pages that belong to each active plugin.
 *
 * Nothing here guesses from plugin names. Every page is attributed by asking
 * PHP where the code behind it lives: the menu page callback, the WooCommerce
 * settings class, the gateway class, the file that registered a post type.
 * Only when that finds nothing does it fall back to scanning the plugin's
 * own PHP files for the menu slugs it registers.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the plugin => admin pages index.
 */
final class PSF_Scanner {

	/**
	 * Entry type ranks: lower wins when two sources find the same page.
	 */
	const RANKS = array(
		'menu'   => 0,
		'woo'    => 1,
		'hidden' => 2,
		'link'   => 3,
		'code'   => 4,
	);

	/**
	 * Files that registered post types / taxonomies, keyed by kind and name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $registrations = array(
		'post_type' => array(),
		'taxonomy'  => array(),
	);

	/**
	 * Active plugins: basename => [ name, version, data, path, is_dir ].
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $plugins = array();

	/**
	 * Found entries: basename => key => entry.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private $entries = array();

	/**
	 * Every menu page seen, attributed or not: slug => entry.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $menu_index = array();

	/**
	 * Insertion counter, keeps sort order stable.
	 *
	 * @var int
	 */
	private $seq = 0;

	/**
	 * Remember which file registers each post type and taxonomy (admin only).
	 */
	public static function track_registrations() {
		if ( ! is_admin() ) {
			return;
		}

		add_action(
			'registered_post_type',
			static function ( $name ) {
				self::remember( 'post_type', $name );
			}
		);
		add_action(
			'registered_taxonomy',
			static function ( $name ) {
				self::remember( 'taxonomy', $name );
			}
		);
	}

	/**
	 * Store the first plugin file in the current call stack.
	 *
	 * @param string $kind post_type|taxonomy.
	 * @param string $name Object name.
	 */
	private static function remember( $kind, $name ) {
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$own_dir    = wp_normalize_path( PSF_PLUGIN_DIR );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attribution only, no output.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $plugin_dir ) && 0 !== strpos( $file, $own_dir ) ) {
				self::$registrations[ $kind ][ $name ] = $file;
				return;
			}
		}
	}

	/**
	 * Signature of the active plugin set; a change triggers a rebuild.
	 *
	 * @return string
	 */
	public static function signature() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all   = get_plugins();
		$parts = array( PSF_VERSION );
		foreach ( self::active_basenames() as $basename ) {
			$parts[] = $basename . '@' . ( isset( $all[ $basename ]['Version'] ) ? $all[ $basename ]['Version'] : '' );
		}
		sort( $parts );
		return md5( implode( '|', $parts ) );
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

	/**
	 * Build the index. Must run on an admin screen after the menu is built.
	 *
	 * @return array{time:int, sig:string, plugins:array}
	 */
	public function build() {
		$this->load_plugins();
		$this->scan_menus();
		$this->scan_woocommerce();
		$this->scan_action_links();
		$this->scan_code();

		$plugins = array();
		foreach ( $this->plugins as $basename => $plugin ) {
			$entries = isset( $this->entries[ $basename ] ) ? array_values( $this->entries[ $basename ] ) : array();
			// Real menu/WooCommerce pages first, settings-looking pages first within
			// each group, so clicking the plugin name lands on its settings.
			$sort_key = static function ( $entry ) {
				return array(
					self::RANKS[ $entry['type'] ] <= self::RANKS['woo'] ? 0 : 1,
					preg_match( '/setting|option|einstellung|config|setup/i', $entry['label'] . ' ' . $entry['url'] ) ? 0 : 1,
					self::RANKS[ $entry['type'] ],
					$entry['seq'],
				);
			};
			usort(
				$entries,
				static function ( $a, $b ) use ( $sort_key ) {
					return $sort_key( $a ) <=> $sort_key( $b );
				}
			);
			foreach ( $entries as &$entry ) {
				unset( $entry['seq'] );
			}
			unset( $entry );

			$plugins[ $basename ] = array(
				'name'    => $plugin['name'],
				'entries' => $entries,
			);
		}

		return array(
			'time'    => time(),
			'sig'     => self::signature(),
			'plugins' => $plugins,
		);
	}

	/**
	 * Collect active plugins and where they live on disk.
	 */
	private function load_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all  = get_plugins();
		$self = plugin_basename( PSF_PLUGIN_FILE );

		foreach ( self::active_basenames() as $basename ) {
			if ( $basename === $self || ! isset( $all[ $basename ] ) ) {
				continue;
			}
			$is_dir = '.' !== dirname( $basename );
			$path   = wp_normalize_path( WP_PLUGIN_DIR . '/' . ( $is_dir ? dirname( $basename ) . '/' : $basename ) );
			$real   = realpath( $is_dir ? WP_PLUGIN_DIR . '/' . dirname( $basename ) : WP_PLUGIN_DIR . '/' . $basename );

			$this->plugins[ $basename ] = array(
				'name'    => wp_strip_all_tags( $all[ $basename ]['Name'] ),
				'version' => $all[ $basename ]['Version'],
				'data'    => $all[ $basename ],
				'is_dir'  => $is_dir,
				'paths'   => array_unique(
					array_filter(
						array(
							$path,
							$real ? wp_normalize_path( $real ) . ( $is_dir ? '/' : '' ) : '',
						)
					)
				),
			);
		}

		uasort(
			$this->plugins,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
	}

	/**
	 * Which active plugin a file belongs to.
	 *
	 * @param string|null $file Absolute path.
	 * @return string|null Plugin basename.
	 */
	private function owner_of_file( $file ) {
		if ( ! $file ) {
			return null;
		}
		$candidates = array( wp_normalize_path( $file ) );
		$real       = realpath( $file );
		if ( $real ) {
			$candidates[] = wp_normalize_path( $real );
		}

		foreach ( $this->plugins as $basename => $plugin ) {
			foreach ( $plugin['paths'] as $path ) {
				foreach ( $candidates as $candidate ) {
					if ( $plugin['is_dir'] ? 0 === strpos( $candidate, $path ) : $candidate === $path ) {
						return $basename;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Source file of a callable. Methods resolve to the concrete class file,
	 * so a plugin class extending WC_Settings_Page is not credited to WooCommerce.
	 *
	 * @param mixed $callback Any callable.
	 * @return string|null
	 */
	private function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && isset( $callback[0] ) ) {
				if ( is_object( $callback[0] ) || ( is_string( $callback[0] ) && class_exists( $callback[0], false ) ) ) {
					$reflection = new ReflectionClass( $callback[0] );
					return $reflection->getFileName() ? $reflection->getFileName() : null;
				}
				return null;
			}
			if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$reflection = new ReflectionFunction( $callback );
				return $reflection->getFileName() ? $reflection->getFileName() : null;
			}
			if ( is_object( $callback ) ) {
				$reflection = new ReflectionClass( $callback );
				return $reflection->getFileName() ? $reflection->getFileName() : null;
			}
		} catch ( ReflectionException $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Owner of the first callback on a hook that belongs to an active plugin.
	 *
	 * @param string $hook Hook name.
	 * @return string|null
	 */
	private function owner_of_hook( $hook ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return null;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$owner = $this->owner_of_file( $this->callback_file( $callback['function'] ) );
				if ( $owner ) {
					return $owner;
				}
			}
		}
		return null;
	}

	/**
	 * Add an entry for a plugin, merging duplicates of the same screen.
	 *
	 * @param string $basename Plugin basename.
	 * @param string $label    Breadcrumb, e.g. "Settings › Foo".
	 * @param string $url      Admin-relative URL.
	 * @param string $type     menu|woo|hidden|link|code.
	 */
	private function add_entry( $basename, $label, $url, $type ) {
		if ( ! isset( $this->plugins[ $basename ] ) || '' === $url || '' === trim( $label ) ) {
			return;
		}
		$key = $this->screen_key( $url );
		if ( isset( $this->entries[ $basename ][ $key ] ) && self::RANKS[ $this->entries[ $basename ][ $key ]['type'] ] <= self::RANKS[ $type ] ) {
			return;
		}
		$this->entries[ $basename ][ $key ] = array(
			'label' => $label,
			'url'   => $url,
			'type'  => $type,
			'seq'   => isset( $this->entries[ $basename ][ $key ] ) ? $this->entries[ $basename ][ $key ]['seq'] : $this->seq++,
		);
	}

	/**
	 * Identity of an admin screen: "options-general.php?page=x" and
	 * "admin.php?page=x" render the same page.
	 *
	 * @param string $url Admin-relative URL.
	 * @return string
	 */
	private function screen_key( $url ) {
		$query = array();
		$parts = explode( '?', $url, 2 );
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
		}
		if ( ! empty( $query['page'] ) && is_string( $query['page'] ) ) {
			$key = 'page=' . $query['page'];
			foreach ( array( 'tab', 'section' ) as $arg ) {
				if ( ! empty( $query[ $arg ] ) && is_string( $query[ $arg ] ) ) {
					$key .= '&' . $arg . '=' . strtolower( $query[ $arg ] );
				}
			}
			return $key;
		}
		return $url;
	}

	/**
	 * Strip count bubbles and markup from a menu title.
	 *
	 * @param string $title Raw menu title.
	 * @return string
	 */
	private function clean( $title ) {
		$title = preg_replace( '#<span[^>]*class=["\'][^"\']*(count|update-plugins|awaiting-mod|pending|plugin-count|menu-counter|badge)[^"\']*["\'][^>]*>.*?</span>\s*(</span>)?#is', '', (string) $title );
		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Admin-relative URL of a menu item, mirroring wp-admin/menu-header.php.
	 *
	 * @param string $slug    Menu slug.
	 * @param string $parent  Parent slug ('' for top level).
	 * @param string $hook    Page hook name.
	 * @param bool   $visible Whether the parent is a visible menu.
	 * @return string '' for external links.
	 */
	private function page_url( $slug, $parent, $hook, $visible ) {
		$is_plugin_page = has_action( $hook )
			|| ( file_exists( WP_PLUGIN_DIR . '/' . $slug ) && ! is_dir( WP_PLUGIN_DIR . '/' . $slug ) && ! file_exists( ABSPATH . 'wp-admin/' . $slug ) );

		if ( ! $is_plugin_page ) {
			return preg_match( '#^[a-z][a-z0-9+.-]*://#i', $slug ) ? '' : $slug;
		}
		if ( '' !== $parent && $visible ) {
			$parent_file = strtok( $parent, '?' );
			if ( file_exists( ABSPATH . 'wp-admin/' . $parent_file )
				|| ( file_exists( WP_PLUGIN_DIR . '/' . $parent_file ) && ! is_dir( WP_PLUGIN_DIR . '/' . $parent ) ) ) {
				return add_query_arg( 'page', $slug, $parent );
			}
		}
		return 'admin.php?page=' . $slug;
	}

	/**
	 * Which plugin owns a menu page.
	 *
	 * @param string $slug Menu slug.
	 * @param string $hook Page hook name.
	 * @return string|null
	 */
	private function owner_of_page( $slug, $hook ) {
		$owner = $this->owner_of_hook( $hook );
		if ( $owner ) {
			return $owner;
		}
		if ( false === strpos( $slug, '?' ) && is_file( WP_PLUGIN_DIR . '/' . $slug ) ) {
			$owner = $this->owner_of_file( WP_PLUGIN_DIR . '/' . $slug );
			if ( $owner ) {
				return $owner;
			}
		}
		foreach ( array( 'taxonomy', 'post_type' ) as $kind ) {
			if ( preg_match( '/[?&]' . $kind . '=([^&#]+)/', $slug, $m ) ) {
				$name = urldecode( $m[1] );
				if ( isset( self::$registrations[ $kind ][ $name ] ) ) {
					return $this->owner_of_file( self::$registrations[ $kind ][ $name ] );
				}
				return null;
			}
		}
		return $this->owner_of_hook( 'load-' . $hook );
	}

	/**
	 * Walk the admin menu and submenus, including hidden pages.
	 */
	private function scan_menus() {
		global $menu, $submenu;

		$titles = array();
		foreach ( (array) $menu as $item ) {
			if ( ! empty( $item[2] ) ) {
				$titles[ $item[2] ] = $this->clean( isset( $item[0] ) ? $item[0] : '' );
			}
		}

		foreach ( (array) $menu as $item ) {
			if ( empty( $item[2] ) || '' === $titles[ $item[2] ] ) {
				continue; // Separator.
			}
			$this->index_page( $item[2], '', $titles[ $item[2] ], 'menu' );
		}

		foreach ( (array) $submenu as $parent => $items ) {
			$parent  = (string) $parent;
			$visible = isset( $titles[ $parent ] ) && '' !== $titles[ $parent ];
			foreach ( (array) $items as $sub ) {
				if ( empty( $sub[2] ) ) {
					continue;
				}
				$title = $this->clean( isset( $sub[0] ) ? $sub[0] : '' );
				if ( '' === $title ) {
					$title = $this->clean( isset( $sub[3] ) ? $sub[3] : $sub[2] );
				}
				if ( $visible ) {
					$this->index_page( $sub[2], $parent, $titles[ $parent ] . ' › ' . $title, 'menu' );
				} else {
					$this->index_page( $sub[2], $parent, $title, 'hidden' );
				}
			}
		}
	}

	/**
	 * Attribute one menu page and remember it for the code scan.
	 *
	 * @param string $slug   Menu slug.
	 * @param string $parent Parent slug.
	 * @param string $label  Breadcrumb.
	 * @param string $type   menu|hidden.
	 */
	private function index_page( $slug, $parent, $label, $type ) {
		$hook = get_plugin_page_hookname( $slug, $parent );
		$url  = $this->page_url( $slug, $parent, $hook, 'menu' === $type );
		if ( '' === $url ) {
			return;
		}

		// Only plugin pages ("?page=" or a plugin file) are candidates for the code scan.
		$is_plugin_page = false !== strpos( $url, 'page=' ) || is_file( WP_PLUGIN_DIR . '/' . $slug );
		if ( $is_plugin_page && ( ! isset( $this->menu_index[ $slug ] ) || ( 'hidden' === $this->menu_index[ $slug ]['type'] && 'menu' === $type ) ) ) {
			$this->menu_index[ $slug ] = array(
				'label' => $label,
				'url'   => $url,
				'type'  => $type,
			);
		}

		$owner = $this->owner_of_page( $slug, $hook );
		if ( $owner ) {
			$this->add_entry( $owner, $label, $url, $type );
		}
	}

	/**
	 * Links plugins add to their row on the Plugins screen ("Settings").
	 */
	private function scan_action_links() {
		foreach ( $this->plugins as $basename => $plugin ) {
			$links = array();
			ob_start();
			try {
				$links = apply_filters( "plugin_action_links_{$basename}", array(), $basename, $plugin['data'], 'all' );
				$links = apply_filters( 'plugin_action_links', $links, $basename, $plugin['data'], 'all' );
			} catch ( Throwable $e ) {
				$links = array();
			}
			ob_end_clean();

			foreach ( (array) $links as $html ) {
				if ( ! is_string( $html ) || ! preg_match( '/href\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
					continue;
				}
				$url  = $this->admin_relative( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) );
				$text = $this->clean( $html );
				if ( null === $url || '' === $text || preg_match( '/[?&](_wpnonce|action=(de)?activate|page=plugin-check(&|$))/', $url ) ) {
					continue;
				}
				$this->add_entry( $basename, $text, $url, 'link' );
			}
		}
	}

	/**
	 * Turn an admin URL into an admin-relative one; null for anything else.
	 *
	 * @param string $href URL.
	 * @return string|null
	 */
	private function admin_relative( $href ) {
		$href  = trim( $href );
		$admin = admin_url();
		if ( '' === $href || '#' === $href[0] || preg_match( '/^(javascript|mailto):/i', $href ) ) {
			return null;
		}
		if ( 0 === strpos( $href, $admin ) ) {
			return substr( $href, strlen( $admin ) );
		}
		if ( preg_match( '#^(https?:)?//#i', $href ) ) {
			$path = wp_parse_url( $href, PHP_URL_PATH );
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( $host && wp_parse_url( $admin, PHP_URL_HOST ) === $host && $path && false !== strpos( $path, '/wp-admin/' ) ) {
				return substr( $href, strpos( $href, '/wp-admin/' ) + 10 );
			}
			return null;
		}
		if ( 0 === strpos( $href, '/' ) ) {
			$pos = strpos( $href, '/wp-admin/' );
			return false === $pos ? null : substr( $href, $pos + 10 );
		}
		return preg_match( '/^[a-z0-9_-]+\.php/i', $href ) ? $href : null;
	}

	/**
	 * WooCommerce settings tabs, sections, gateways, shipping methods,
	 * integrations and emails added by other plugins.
	 */
	private function scan_woocommerce() {
		global $wp_filter;

		if ( ! function_exists( 'WC' ) || ! defined( 'WC_PLUGIN_FILE' ) ) {
			return;
		}
		$wc = plugin_basename( WC_PLUGIN_FILE );

		ob_start();
		try {
			if ( class_exists( 'WC_Admin_Settings' ) && method_exists( 'WC_Admin_Settings', 'get_settings_pages' ) ) {
				WC_Admin_Settings::get_settings_pages();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		ob_end_clean();

		$settings  = __( 'WooCommerce', 'plugin-settings-finder' ) . ' › ' . __( 'Settings', 'plugin-settings-finder' );
		$tab_names = array();
		try {
			$tab_names = (array) apply_filters( 'woocommerce_settings_tabs_array', array() );
		} catch ( Throwable $e ) {
			$tab_names = array();
		}
		$tab_label = static function ( $tab ) use ( $tab_names, $settings ) {
			return $settings . ' › ' . ( isset( $tab_names[ $tab ] ) ? wp_strip_all_tags( $tab_names[ $tab ] ) : ucfirst( $tab ) );
		};

		// Tabs: call each foreign callback on its own to see what it adds.
		foreach ( $this->foreign_callbacks( 'woocommerce_settings_tabs_array', $wc ) as $owner => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				foreach ( $this->probe( $callback ) as $tab => $label ) {
					$this->add_entry( $owner, $settings . ' › ' . wp_strip_all_tags( (string) $label ), 'admin.php?page=wc-settings&tab=' . rawurlencode( (string) $tab ), 'woo' );
				}
			}
		}

		// Sections added to any tab.
		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( 0 !== strpos( (string) $hook, 'woocommerce_get_sections_' ) ) {
				continue;
			}
			$tab = substr( $hook, strlen( 'woocommerce_get_sections_' ) );
			foreach ( $this->foreign_callbacks( $hook, $wc ) as $owner => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					foreach ( $this->probe( $callback ) as $section => $label ) {
						if ( '' === (string) $section ) {
							continue;
						}
						$this->add_entry( $owner, $tab_label( $tab ) . ' › ' . wp_strip_all_tags( (string) $label ), 'admin.php?page=wc-settings&tab=' . rawurlencode( $tab ) . '&section=' . rawurlencode( (string) $section ), 'woo' );
					}
				}
			}
		}

		$objects = array(
			'checkout'    => array( 'payment_gateways', 'payment_gateways' ),
			'shipping'    => array( 'shipping', 'get_shipping_methods' ),
			'integration' => array( 'integrations', 'get_integrations' ),
			'email'       => array( 'mailer', 'get_emails' ),
		);
		foreach ( $objects as $tab => $source ) {
			ob_start();
			try {
				$container = 'integrations' === $source[0] ? WC()->integrations : WC()->{$source[0]}();
				$items     = ( is_object( $container ) && method_exists( $container, $source[1] ) ) ? (array) $container->{$source[1]}() : array();
			} catch ( Throwable $e ) {
				$items = array();
			}
			ob_end_clean();

			foreach ( $items as $key => $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}
				$owner = $this->owner_of_file( $this->callback_file( $item ) );
				if ( ! $owner || $owner === $wc ) {
					continue;
				}
				$section = 'email' === $tab ? strtolower( (string) $key ) : strtolower( (string) ( isset( $item->id ) ? $item->id : $key ) );
				$title   = method_exists( $item, 'get_method_title' ) ? $item->get_method_title() : '';
				if ( '' === (string) $title && method_exists( $item, 'get_title' ) ) {
					$title = $item->get_title();
				}
				if ( '' === (string) $title ) {
					$title = isset( $item->method_title ) ? $item->method_title : ( isset( $item->title ) ? $item->title : $section );
				}
				$this->add_entry( $owner, $tab_label( $tab ) . ' › ' . $this->clean( (string) $title ), 'admin.php?page=wc-settings&tab=' . $tab . '&section=' . rawurlencode( $section ), 'woo' );
			}
		}
	}

	/**
	 * Callbacks on a hook grouped by owning plugin, excluding one plugin.
	 *
	 * @param string $hook    Hook name.
	 * @param string $exclude Basename to ignore.
	 * @return array<string, callable[]>
	 */
	private function foreign_callbacks( $hook, $exclude ) {
		global $wp_filter;

		$found = array();
		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return $found;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$owner = $this->owner_of_file( $this->callback_file( $callback['function'] ) );
				if ( $owner && $owner !== $exclude ) {
					$found[ $owner ][] = $callback['function'];
				}
			}
		}
		return $found;
	}

	/**
	 * Call a filter callback with an empty array and return what it added.
	 *
	 * @param callable $callback Callback.
	 * @return array
	 */
	private function probe( $callback ) {
		ob_start();
		try {
			$result = call_user_func( $callback, array() );
		} catch ( Throwable $e ) {
			$result = array();
		}
		ob_end_clean();
		return is_array( $result ) ? array_filter( $result, 'is_scalar' ) : array();
	}

	/**
	 * Last resort for plugins with nothing found: read their PHP files for
	 * the slugs they hand to add_*_page() or link to via "page=", and match
	 * those against pages that are actually registered right now.
	 */
	private function scan_code() {
		$cache   = get_option( 'psf_code_cache', array() );
		$cache   = is_array( $cache ) ? $cache : array();
		$changed = false;

		foreach ( $this->plugins as $basename => $plugin ) {
			if ( ! empty( $this->entries[ $basename ] ) ) {
				continue;
			}
			if ( ! isset( $cache[ $basename ] ) || $cache[ $basename ]['v'] !== $plugin['version'] ) {
				$cache[ $basename ] = array(
					'v'     => $plugin['version'],
					'slugs' => $this->slugs_in_code( $plugin ),
				);
				$changed            = true;
			}
			foreach ( $cache[ $basename ]['slugs'] as $slug ) {
				if ( isset( $this->menu_index[ $slug ] ) ) {
					$page = $this->menu_index[ $slug ];
					$this->add_entry( $basename, $page['label'], $page['url'], 'code' );
				}
			}
		}

		$cache = array_intersect_key( $cache, $this->plugins );
		if ( $changed ) {
			update_option( 'psf_code_cache', $cache, false );
		}
	}

	/**
	 * Candidate page slugs mentioned in a plugin's PHP code.
	 *
	 * @param array $plugin Plugin record.
	 * @return string[]
	 */
	private function slugs_in_code( array $plugin ) {
		$root  = $plugin['paths'][0];
		$files = array();
		if ( ! $plugin['is_dir'] ) {
			$files[] = $root;
		} elseif ( is_dir( $root ) ) {
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveCallbackFilterIterator(
						new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
						static function ( $current ) {
							if ( $current->isDir() ) {
								return ! in_array( strtolower( $current->getFilename() ), array( 'node_modules', '.git', 'tests', 'test', 'languages', 'assets' ), true );
							}
							return 'php' === strtolower( $current->getExtension() ) && $current->getSize() < 1048576;
						}
					)
				);
				foreach ( $iterator as $file ) {
					$files[] = $file->getPathname();
					if ( count( $files ) >= 1500 ) {
						break;
					}
				}
			} catch ( Exception $e ) {
				unset( $e );
			}
		}

		$slugs    = array();
		$deadline = microtime( true ) + 1.5;
		foreach ( $files as $file ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			if ( preg_match_all( '/\badd_(?:sub)?menu_page\s*\(|\badd_(?:options|management|theme|users|dashboard|posts|media|links|pages|comments|plugins)_page\s*\(/i', $code, $calls, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $calls[0] as $call ) {
					$args = substr( $code, $call[1], 600 );
					if ( preg_match_all( '/[\'"]([A-Za-z0-9_\-\.\/]{2,100})[\'"]/', $args, $literals ) ) {
						$slugs = array_merge( $slugs, $literals[1] );
					}
				}
			}
			if ( preg_match_all( '/[?&]page=([A-Za-z0-9_\-]{2,100})/', $code, $links ) ) {
				$slugs = array_merge( $slugs, $links[1] );
			}
		}
		return array_slice( array_values( array_unique( $slugs ) ), 0, 300 );
	}
}
