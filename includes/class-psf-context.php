<?php
/**
 * Shared state and attribution helpers for one index build.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the active plugins, the entries found so far and the helpers every
 * detector needs to answer "which plugin does this code belong to?".
 */
final class PSF_Context {

	/**
	 * Entry type ranks: lower wins when two detectors find the same screen.
	 */
	const RANKS = array(
		'menu'   => 0,
		'woo'    => 1,
		'hidden' => 2,
		'link'   => 3,
		'code'   => 4,
	);

	/**
	 * Active plugins: basename => [ name, version, data, is_dir, paths ].
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $plugins = array();

	/**
	 * Found entries: basename => screen key => entry.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private $entries = array();

	/**
	 * Every plugin menu page seen, attributed or not: slug => page.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $menu_pages = array();

	/**
	 * What plugins without admin pages add instead: basename => usage.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $usage = array();

	/**
	 * Insertion counter, keeps sort order stable.
	 *
	 * @var int
	 */
	private $seq = 0;

	/**
	 * Create a context for a set of plugins.
	 *
	 * @param array<string, array<string, mixed>> $plugins basename => record (see PSF_Scanner::load_plugins()).
	 */
	public function __construct( array $plugins ) {
		$this->plugins = $plugins;
	}

	/**
	 * Active plugins, sorted by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function plugins() {
		return $this->plugins;
	}

	/**
	 * Whether a detector already found at least one page for a plugin.
	 *
	 * @param string $basename Plugin basename.
	 * @return bool
	 */
	public function has_entries( $basename ) {
		return ! empty( $this->entries[ $basename ] );
	}

	/**
	 * Add an entry for a plugin, merging duplicates of the same screen.
	 *
	 * @param string $basename Plugin basename.
	 * @param string $label    Breadcrumb, e.g. "Settings › Foo".
	 * @param string $url      Admin-relative URL.
	 * @param string $type     menu|woo|hidden|link|code.
	 */
	public function add_entry( $basename, $label, $url, $type ) {
		if ( ! isset( $this->plugins[ $basename ], self::RANKS[ $type ] ) || '' === $url || '' === trim( $label ) ) {
			return;
		}
		$key = self::screen_key( $url );
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
	 * Entries of one plugin, in insertion order.
	 *
	 * @param string $basename Plugin basename.
	 * @return array<int, array<string, mixed>>
	 */
	public function entries( $basename ) {
		return isset( $this->entries[ $basename ] ) ? array_values( $this->entries[ $basename ] ) : array();
	}

	/**
	 * Remember a plugin menu page, whoever owns it (used by the code scan).
	 *
	 * @param string $slug  Menu slug.
	 * @param string $label Breadcrumb.
	 * @param string $url   Admin-relative URL.
	 * @param string $type  menu|hidden.
	 */
	public function add_menu_page( $slug, $label, $url, $type ) {
		if ( ! isset( $this->menu_pages[ $slug ] ) || ( 'hidden' === $this->menu_pages[ $slug ]['type'] && 'menu' === $type ) ) {
			$this->menu_pages[ $slug ] = array(
				'label' => $label,
				'url'   => $url,
				'type'  => $type,
			);
		}
	}

	/**
	 * A remembered plugin menu page.
	 *
	 * @param string $slug Menu slug.
	 * @return array<string, string>|null
	 */
	public function menu_page( $slug ) {
		return isset( $this->menu_pages[ $slug ] ) ? $this->menu_pages[ $slug ] : null;
	}

	/**
	 * Store what a plugin without admin pages adds instead.
	 *
	 * @param string $basename Plugin basename.
	 * @param array  $usage    Usage record.
	 */
	public function set_usage( $basename, array $usage ) {
		$this->usage[ $basename ] = $usage;
	}

	/**
	 * Usage record of a plugin.
	 *
	 * @param string $basename Plugin basename.
	 * @return array
	 */
	public function usage( $basename ) {
		return isset( $this->usage[ $basename ] ) ? $this->usage[ $basename ] : array();
	}

	/**
	 * Which active plugin a file belongs to.
	 *
	 * @param string|null $file Absolute path.
	 * @return string|null Plugin basename.
	 */
	public function owner_of_file( $file ) {
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
	 * Which active plugin a callable belongs to.
	 *
	 * @param mixed $callback Any callable or object.
	 * @return string|null Plugin basename.
	 */
	public function owner_of_callback( $callback ) {
		return $this->owner_of_file( self::callback_file( $callback ) );
	}

	/**
	 * Owner of the first callback on a hook that belongs to an active plugin.
	 *
	 * @param string $hook Hook name.
	 * @return string|null
	 */
	public function owner_of_hook( $hook ) {
		foreach ( self::hook_callbacks( $hook ) as $callback ) {
			$owner = $this->owner_of_callback( $callback );
			if ( $owner ) {
				return $owner;
			}
		}
		return null;
	}

	/**
	 * Callbacks on a hook grouped by owning plugin, excluding one plugin.
	 *
	 * @param string      $hook    Hook name.
	 * @param string|null $exclude Basename to ignore.
	 * @return array<string, callable[]>
	 */
	public function callbacks_by_owner( $hook, $exclude = null ) {
		$found = array();
		foreach ( self::hook_callbacks( $hook ) as $callback ) {
			$owner = $this->owner_of_callback( $callback );
			if ( $owner && $owner !== $exclude ) {
				$found[ $owner ][] = $callback;
			}
		}
		return $found;
	}

	/**
	 * All callables registered on a hook, in priority order.
	 *
	 * @param string $hook Hook name.
	 * @return array<int, mixed>
	 */
	private static function hook_callbacks( $hook ) {
		global $wp_filter;

		$callables = array();
		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return $callables;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$callables[] = $callback['function'];
			}
		}
		return $callables;
	}

	/**
	 * Source file of a callable. Methods resolve to the concrete class file,
	 * so a plugin class extending WC_Settings_Page is not credited to WooCommerce.
	 *
	 * @param mixed $callback Any callable or object.
	 * @return string|null
	 */
	public static function callback_file( $callback ) {
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
	 * Call a filter callback with an empty array and return what it added.
	 *
	 * Used to learn which WooCommerce tabs or sections one plugin contributes.
	 * Output is swallowed and exceptions are caught; the callback is code the
	 * site already runs on every WooCommerce settings screen.
	 *
	 * @param callable $callback Callback.
	 * @return array<string|int, scalar>
	 */
	public static function probe( $callback ) {
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
	 * Identity of an admin screen: "options-general.php?page=x" and
	 * "admin.php?page=x" render the same page.
	 *
	 * @param string $url Admin-relative URL.
	 * @return string
	 */
	public static function screen_key( $url ) {
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
	 * Strip count bubbles and markup from a menu title or link.
	 *
	 * @param string $title Raw HTML title.
	 * @return string Plain text.
	 */
	public static function clean( $title ) {
		$title = preg_replace( '#<span[^>]*class=["\'][^"\']*(count|update-plugins|awaiting-mod|pending|plugin-count|menu-counter|badge)[^"\']*["\'][^>]*>.*?</span>\s*(</span>)?#is', '', (string) $title );
		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Turn an admin URL into an admin-relative one; null for anything else.
	 *
	 * @param string $href URL.
	 * @return string|null
	 */
	public static function admin_relative( $href ) {
		$href  = trim( (string) $href );
		$admin = admin_url();
		if ( '' === $href || '#' === $href[0] || preg_match( '/^(javascript|mailto|data):/i', $href ) ) {
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
}
