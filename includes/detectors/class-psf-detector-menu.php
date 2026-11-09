<?php
/**
 * Admin menu detector.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks the admin menu and submenus (including hidden pages registered
 * without a visible parent) and attributes each page to the plugin whose
 * code renders it.
 */
final class PSF_Detector_Menu implements PSF_Detector {

	/**
	 * Find menu pages.
	 *
	 * @param PSF_Context $context Shared build state.
	 */
	public function detect( PSF_Context $context ) {
		global $menu, $submenu;

		$titles = array();
		foreach ( (array) $menu as $item ) {
			if ( ! empty( $item[2] ) ) {
				$titles[ $item[2] ] = PSF_Context::clean( isset( $item[0] ) ? $item[0] : '' );
			}
		}

		foreach ( (array) $menu as $item ) {
			if ( empty( $item[2] ) || '' === $titles[ $item[2] ] ) {
				continue; // Separator.
			}
			$this->add_page( $context, $item[2], '', $titles[ $item[2] ], 'menu' );
		}

		foreach ( (array) $submenu as $parent => $items ) {
			$parent  = (string) $parent;
			$visible = isset( $titles[ $parent ] ) && '' !== $titles[ $parent ];
			foreach ( (array) $items as $sub ) {
				if ( empty( $sub[2] ) ) {
					continue;
				}
				$title = PSF_Context::clean( isset( $sub[0] ) ? $sub[0] : '' );
				if ( '' === $title ) {
					$title = PSF_Context::clean( isset( $sub[3] ) ? $sub[3] : $sub[2] );
				}
				if ( $visible ) {
					$this->add_page( $context, $sub[2], $parent, $titles[ $parent ] . ' › ' . $title, 'menu' );
				} else {
					$this->add_page( $context, $sub[2], $parent, $title, 'hidden' );
				}
			}
		}
	}

	/**
	 * Attribute one menu page and remember it for the code scan.
	 *
	 * @param PSF_Context $context Shared build state.
	 * @param string      $slug    Menu slug.
	 * @param string      $parent_slug Parent slug.
	 * @param string      $label   Breadcrumb.
	 * @param string      $type    menu|hidden.
	 */
	private function add_page( PSF_Context $context, $slug, $parent_slug, $label, $type ) {
		$hook = get_plugin_page_hookname( $slug, $parent_slug );
		$url  = self::page_url( $slug, $parent_slug, $hook, 'menu' === $type );
		if ( '' === $url ) {
			return;
		}

		// Only plugin pages ("?page=" or a plugin file) are candidates for the code scan.
		if ( false !== strpos( $url, 'page=' ) || is_file( WP_PLUGIN_DIR . '/' . $slug ) ) {
			$context->add_menu_page( $slug, $label, $url, $type );
		}

		$owner = $this->owner( $context, $slug, $hook );
		if ( $owner ) {
			$context->add_entry( $owner, $label, $url, $type );
		}
	}

	/**
	 * Admin-relative URL of a menu item, mirroring wp-admin/menu-header.php.
	 *
	 * @param string $slug        Menu slug.
	 * @param string $parent_slug Parent slug ('' for top level).
	 * @param string $hook        Page hook name.
	 * @param bool   $visible     Whether the parent is a visible menu.
	 * @return string '' for external links.
	 */
	public static function page_url( $slug, $parent_slug, $hook, $visible ) {
		$is_plugin_page = has_action( $hook )
			|| ( file_exists( WP_PLUGIN_DIR . '/' . $slug ) && ! is_dir( WP_PLUGIN_DIR . '/' . $slug ) && ! file_exists( ABSPATH . 'wp-admin/' . $slug ) );

		if ( ! $is_plugin_page ) {
			return preg_match( '#^[a-z][a-z0-9+.-]*://#i', $slug ) ? '' : $slug;
		}
		if ( '' !== $parent_slug && $visible ) {
			$parent_file = strtok( $parent_slug, '?' );
			if ( file_exists( ABSPATH . 'wp-admin/' . $parent_file )
				|| ( file_exists( WP_PLUGIN_DIR . '/' . $parent_file ) && ! is_dir( WP_PLUGIN_DIR . '/' . $parent_slug ) ) ) {
				return add_query_arg( 'page', $slug, $parent_slug );
			}
		}
		return 'admin.php?page=' . $slug;
	}

	/**
	 * Which plugin owns a menu page.
	 *
	 * @param PSF_Context $context Shared build state.
	 * @param string      $slug    Menu slug.
	 * @param string      $hook    Page hook name.
	 * @return string|null
	 */
	private function owner( PSF_Context $context, $slug, $hook ) {
		$owner = $context->owner_of_hook( $hook );
		if ( $owner ) {
			return $owner;
		}
		if ( false === strpos( $slug, '?' ) && is_file( WP_PLUGIN_DIR . '/' . $slug ) ) {
			$owner = $context->owner_of_file( WP_PLUGIN_DIR . '/' . $slug );
			if ( $owner ) {
				return $owner;
			}
		}
		foreach ( array( 'taxonomy', 'post_type' ) as $kind ) {
			if ( preg_match( '/[?&]' . $kind . '=([^&#]+)/', $slug, $m ) ) {
				return $context->owner_of_file( PSF_Registrations::file( $kind, urldecode( $m[1] ) ) );
			}
		}
		return $context->owner_of_hook( 'load-' . $hook );
	}
}
