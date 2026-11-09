<?php
/**
 * "What does it add instead?" detector.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For plugins without any admin page: which blocks, shortcodes and widgets
 * they register (live registries, attributed by callback/class file, plus
 * block.json files and register_block_type() calls), and what their readme
 * says about itself. File results are cached per plugin version in the
 * `psf_usage_cache` option. Runs last, after every page detector.
 */
final class PSF_Detector_Usage implements PSF_Detector {

	const CACHE = 'psf_usage_cache';

	/**
	 * Readme keywords => hint key.
	 */
	const MENTIONS = array(
		'blocks'     => '/\bblocks?\b|gutenberg|block editor/',
		'shortcodes' => '/shortcode/',
		'widgets'    => '/\bwidgets?\b/',
		'customizer' => '/customi[sz]er/',
		'cli'        => '/wp[- ]cli/',
		'auto'       => '/no (settings|configuration|options)|works out of the box|nothing to configure|zero[- ]config|just activate/',
	);

	/**
	 * Describe plugins without pages.
	 *
	 * @param PSF_Context $context Shared build state.
	 */
	public function detect( PSF_Context $context ) {
		$targets = array();
		foreach ( array_keys( $context->plugins() ) as $basename ) {
			if ( ! $context->has_entries( $basename ) ) {
				$targets[ $basename ] = array(
					'blocks'     => array(),
					'shortcodes' => array(),
					'widgets'    => array(),
					'readme'     => '',
					'mentions'   => array(),
				);
			}
		}
		if ( ! $targets ) {
			return;
		}

		$this->collect_runtime( $context, $targets );

		$cache   = get_option( self::CACHE, array() );
		$cache   = is_array( $cache ) ? $cache : array();
		$changed = false;
		$plugins = $context->plugins();

		foreach ( $targets as $basename => $usage ) {
			$plugin = $plugins[ $basename ];
			if ( ! isset( $cache[ $basename ]['v'] ) || $cache[ $basename ]['v'] !== $plugin['version'] ) {
				$cache[ $basename ] = array_merge( array( 'v' => $plugin['version'] ), self::read_plugin_files( $plugin ) );
				$changed            = true;
			}
			$context->set_usage( $basename, self::merge( $usage, $cache[ $basename ] ) );
		}

		$pruned = array_intersect_key( $cache, $plugins );
		if ( $changed || count( $pruned ) !== count( $cache ) ) {
			update_option( self::CACHE, $pruned, false );
		}
	}

	/**
	 * Shortcodes and widgets from the live registries.
	 *
	 * @param PSF_Context $context Shared build state.
	 * @param array       $targets basename => usage, filled in place.
	 */
	private function collect_runtime( PSF_Context $context, array &$targets ) {
		global $shortcode_tags, $wp_widget_factory;

		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			$owner = $context->owner_of_callback( $callback );
			if ( $owner && isset( $targets[ $owner ] ) ) {
				$targets[ $owner ]['shortcodes'][] = '[' . $tag . ']';
			}
		}

		if ( is_object( $wp_widget_factory ) && ! empty( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				$owner = is_object( $widget ) ? $context->owner_of_callback( $widget ) : null;
				if ( $owner && isset( $targets[ $owner ] ) ) {
					$targets[ $owner ]['widgets'][] = PSF_Context::clean( isset( $widget->name ) ? $widget->name : get_class( $widget ) );
				}
			}
		}
	}

	/**
	 * Combine runtime findings with the cached file findings.
	 *
	 * @param array $usage Runtime usage.
	 * @param array $files Cached file results (blocks, readme, mentions).
	 * @return array
	 */
	private static function merge( array $usage, array $files ) {
		$registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;
		foreach ( (array) $files['blocks'] as $name => $title ) {
			$registered = $registry ? $registry->get_registered( $name ) : null;
			if ( $registered && ! empty( $registered->title ) ) {
				$title = $registered->title;
			}
			$usage['blocks'][] = '' !== (string) $title ? (string) $title : (string) $name;
		}
		foreach ( array( 'blocks', 'shortcodes', 'widgets' ) as $key ) {
			$usage[ $key ] = array_values( array_unique( $usage[ $key ] ) );
		}
		$usage['readme'] = (string) $files['readme'];

		// Readme hints only for things not already proven at runtime.
		$labels = array(
			'blocks'     => __( 'blocks', 'plugin-settings-finder' ),
			'shortcodes' => __( 'shortcodes', 'plugin-settings-finder' ),
			'widgets'    => __( 'widgets', 'plugin-settings-finder' ),
			'customizer' => __( 'Customizer', 'plugin-settings-finder' ),
			'cli'        => __( 'WP-CLI', 'plugin-settings-finder' ),
			'auto'       => __( 'works without configuration', 'plugin-settings-finder' ),
		);
		foreach ( (array) $files['mentions'] as $mention ) {
			if ( isset( $labels[ $mention ] ) && empty( $usage[ $mention ] ) ) {
				$usage['mentions'][] = $labels[ $mention ];
			}
		}
		return $usage;
	}

	/**
	 * Blocks from block.json and register_block_type(), plus the readme.
	 *
	 * @param array $plugin Plugin record.
	 * @return array{blocks:array<string,string>, readme:string, mentions:string[]}
	 */
	private static function read_plugin_files( array $plugin ) {
		$blocks = array();
		$files  = PSF_Files::find(
			$plugin,
			static function ( $file ) {
				return 'block.json' === $file->getFilename() || 'php' === strtolower( $file->getExtension() );
			},
			array( 'vendor' )
		);

		$php = array();
		foreach ( $files as $file ) {
			if ( 'block.json' === basename( $file ) ) {
				$json = json_decode( PSF_Files::read( $file ), true );
				if ( is_array( $json ) && ! empty( $json['name'] ) && is_string( $json['name'] ) ) {
					$blocks[ $json['name'] ] = isset( $json['title'] ) && is_string( $json['title'] ) ? $json['title'] : '';
				}
			} else {
				$php[] = $file;
			}
		}
		PSF_Files::read_each(
			$php,
			static function ( $code ) use ( &$blocks ) {
				foreach ( self::blocks_in_code( $code ) as $name ) {
					if ( ! isset( $blocks[ $name ] ) ) {
						$blocks[ $name ] = '';
					}
				}
			},
			1.0
		);

		$readme = '';
		if ( $plugin['is_dir'] ) {
			foreach ( array( 'readme.txt', 'README.txt', 'readme.md', 'README.md' ) as $name ) {
				if ( is_file( $plugin['paths'][0] . $name ) ) {
					$readme = PSF_Files::read( $plugin['paths'][0] . $name, 200000 );
					break;
				}
			}
		}

		return array_merge( array( 'blocks' => $blocks ), self::parse_readme( $readme ) );
	}

	/**
	 * Block names passed as string literals to register_block_type().
	 *
	 * @param string $code PHP source.
	 * @return string[]
	 */
	public static function blocks_in_code( $code ) {
		return preg_match_all( '/register_block_type\s*\(\s*[\'"]([a-z0-9-]+\/[a-z0-9-]+)[\'"]/i', $code, $m ) ? array_values( array_unique( $m[1] ) ) : array();
	}

	/**
	 * Short description and keyword hints from a readme.txt or README.md.
	 *
	 * @param string $readme Readme contents.
	 * @return array{readme:string, mentions:string[]}
	 */
	public static function parse_readme( $readme ) {
		$result = array(
			'readme'   => '',
			'mentions' => array(),
		);
		if ( '' === trim( (string) $readme ) ) {
			return $result;
		}

		// Short description: first prose line after the title and the "Key: value" header block.
		$past_title = false;
		foreach ( preg_split( '/\R/', $readme ) as $line ) {
			$line = trim( $line );
			if ( ! $past_title ) {
				$past_title = '' !== $line;
				continue;
			}
			if ( '' === $line || preg_match( '/^(\*\*)?[A-Za-z][A-Za-z \-]{1,30}:(\*\*)?\s/', $line ) ) {
				continue;
			}
			if ( preg_match( '/^(==|#)/', $line ) ) {
				break;
			}
			$result['readme'] = wp_html_excerpt( wp_strip_all_tags( $line ), 160, '…' );
			break;
		}

		$text = strtolower( $readme );
		foreach ( self::MENTIONS as $key => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				$result['mentions'][] = $key;
			}
		}
		return $result;
	}
}
