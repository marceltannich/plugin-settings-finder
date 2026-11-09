<?php
/**
 * Plugins screen row links detector.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the links plugins add to their own row on the Plugins screen
 * ("Settings", "Get started"), as long as they point into wp-admin.
 */
final class PSF_Detector_Action_Links implements PSF_Detector {

	/**
	 * Links that are actions, not pages: nonce links, (de)activation and
	 * Plugin Check's "Check this plugin", which it adds to every row.
	 */
	const IGNORE = '/[?&](_wpnonce|action=(de)?activate|page=plugin-check(&|$))/';

	/**
	 * Find row links.
	 *
	 * @param PSF_Context $context Shared build state.
	 */
	public function detect( PSF_Context $context ) {
		foreach ( $context->plugins() as $basename => $plugin ) {
			foreach ( self::links( $basename, $plugin['data'] ) as $html ) {
				$link = self::parse( $html );
				if ( $link ) {
					$context->add_entry( $basename, $link['text'], $link['url'], 'link' );
				}
			}
		}
	}

	/**
	 * Row links of one plugin, as WordPress would build them.
	 *
	 * @param string $basename Plugin basename.
	 * @param array  $data     Plugin header data.
	 * @return array
	 */
	private static function links( $basename, array $data ) {
		ob_start();
		try {
			$links = apply_filters( "plugin_action_links_{$basename}", array(), $basename, $data, 'all' );
			$links = apply_filters( 'plugin_action_links', $links, $basename, $data, 'all' );
		} catch ( Throwable $e ) {
			$links = array();
		}
		ob_end_clean();
		return (array) $links;
	}

	/**
	 * Text and admin-relative URL of a link, or null if it is not a wp-admin page.
	 *
	 * @param mixed $html Link HTML.
	 * @return array{text:string, url:string}|null
	 */
	public static function parse( $html ) {
		if ( ! is_string( $html ) || ! preg_match( '/href\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			return null;
		}
		$url  = PSF_Context::admin_relative( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) );
		$text = PSF_Context::clean( $html );
		if ( null === $url || '' === $text || preg_match( self::IGNORE, $url ) ) {
			return null;
		}
		return array(
			'text' => $text,
			'url'  => $url,
		);
	}
}
