<?php
/**
 * Source code fallback detector.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Last resort for plugins nobody found pages for: read their PHP files for
 * the slugs they hand to add_*_page() or link to via "page=", and match those
 * against plugin pages that are actually registered right now. Catches menus
 * registered through shared frameworks that live in another plugin.
 * Results are cached per plugin version in the `psf_code_cache` option.
 */
final class PSF_Detector_Code implements PSF_Detector {

	const CACHE = 'psf_code_cache';

	/**
	 * Find pages mentioned in code.
	 *
	 * @param PSF_Context $context Shared build state.
	 */
	public function detect( PSF_Context $context ) {
		$cache   = get_option( self::CACHE, array() );
		$cache   = is_array( $cache ) ? $cache : array();
		$changed = false;

		foreach ( $context->plugins() as $basename => $plugin ) {
			if ( $context->has_entries( $basename ) ) {
				continue;
			}
			if ( ! isset( $cache[ $basename ]['v'] ) || $cache[ $basename ]['v'] !== $plugin['version'] ) {
				$cache[ $basename ] = array(
					'v'     => $plugin['version'],
					'slugs' => self::slugs_in_files( PSF_Files::php( $plugin, array( 'assets' ) ) ),
				);
				$changed            = true;
			}
			foreach ( $cache[ $basename ]['slugs'] as $slug ) {
				$page = $context->menu_page( $slug );
				if ( $page ) {
					$context->add_entry( $basename, $page['label'], $page['url'], 'code' );
				}
			}
		}

		$pruned = array_intersect_key( $cache, $context->plugins() );
		if ( $changed || count( $pruned ) !== count( $cache ) ) {
			update_option( self::CACHE, $pruned, false );
		}
	}

	/**
	 * Candidate page slugs mentioned in PHP files.
	 *
	 * @param string[] $files Absolute paths.
	 * @return string[]
	 */
	private static function slugs_in_files( array $files ) {
		$slugs = array();
		PSF_Files::read_each(
			$files,
			static function ( $code ) use ( &$slugs ) {
				$slugs = array_merge( $slugs, self::slugs_in_code( $code ) );
			}
		);
		return array_slice( array_values( array_unique( $slugs ) ), 0, 300 );
	}

	/**
	 * Candidate page slugs in one piece of PHP code: string literals in the
	 * arguments of add_*_page() calls, and "page=" values in URLs.
	 *
	 * @param string $code PHP source.
	 * @return string[]
	 */
	public static function slugs_in_code( $code ) {
		$slugs = array();
		if ( preg_match_all( '/\badd_(?:sub)?menu_page\s*\(|\badd_(?:options|management|theme|users|dashboard|posts|media|links|pages|comments|plugins)_page\s*\(/i', $code, $calls, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $calls[0] as $call ) {
				if ( preg_match_all( '/[\'"]([A-Za-z0-9_\-\.\/]{2,100})[\'"]/', substr( $code, $call[1], 600 ), $literals ) ) {
					$slugs = array_merge( $slugs, $literals[1] );
				}
			}
		}
		if ( preg_match_all( '/[?&]page=([A-Za-z0-9_\-]{2,100})/', $code, $links ) ) {
			$slugs = array_merge( $slugs, $links[1] );
		}
		return array_values( array_unique( $slugs ) );
	}
}
