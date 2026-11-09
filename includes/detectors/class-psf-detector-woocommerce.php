<?php
/**
 * WooCommerce settings detector.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds what other plugins add to WooCommerce › Settings: tabs, sections in
 * any tab, payment gateways, shipping methods, integrations and emails.
 * WooCommerce's own tabs are skipped; its menu pages come from the menu detector.
 */
final class PSF_Detector_WooCommerce implements PSF_Detector {

	/**
	 * Find WooCommerce settings screens.
	 *
	 * @param PSF_Context $context Shared build state.
	 */
	public function detect( PSF_Context $context ) {
		global $wp_filter;

		if ( ! function_exists( 'WC' ) || ! defined( 'WC_PLUGIN_FILE' ) ) {
			return;
		}
		$wc = plugin_basename( WC_PLUGIN_FILE );

		// Loads the settings page classes, so add-ons hook their tabs in.
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
			return $settings . ' › ' . ( isset( $tab_names[ $tab ] ) ? wp_strip_all_tags( (string) $tab_names[ $tab ] ) : ucfirst( $tab ) );
		};

		// Tabs: ask each foreign callback on its own what it adds.
		foreach ( $context->callbacks_by_owner( 'woocommerce_settings_tabs_array', $wc ) as $owner => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				foreach ( PSF_Context::probe( $callback ) as $tab => $label ) {
					$context->add_entry( $owner, $settings . ' › ' . wp_strip_all_tags( (string) $label ), 'admin.php?page=wc-settings&tab=' . rawurlencode( (string) $tab ), 'woo' );
				}
			}
		}

		// Sections added to any tab.
		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( 0 !== strpos( (string) $hook, 'woocommerce_get_sections_' ) ) {
				continue;
			}
			$tab = substr( $hook, strlen( 'woocommerce_get_sections_' ) );
			foreach ( $context->callbacks_by_owner( $hook, $wc ) as $owner => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					foreach ( PSF_Context::probe( $callback ) as $section => $label ) {
						if ( '' === (string) $section ) {
							continue;
						}
						$context->add_entry( $owner, $tab_label( $tab ) . ' › ' . wp_strip_all_tags( (string) $label ), 'admin.php?page=wc-settings&tab=' . rawurlencode( $tab ) . '&section=' . rawurlencode( (string) $section ), 'woo' );
					}
				}
			}
		}

		foreach ( $this->registered_objects() as $tab => $items ) {
			foreach ( $items as $key => $item ) {
				$owner = is_object( $item ) ? $context->owner_of_callback( $item ) : null;
				if ( ! $owner || $owner === $wc ) {
					continue;
				}
				$section = 'email' === $tab ? strtolower( (string) $key ) : strtolower( (string) ( isset( $item->id ) ? $item->id : $key ) );
				$context->add_entry( $owner, $tab_label( $tab ) . ' › ' . self::object_title( $item, $section ), 'admin.php?page=wc-settings&tab=' . $tab . '&section=' . rawurlencode( $section ), 'woo' );
			}
		}
	}

	/**
	 * Gateways, shipping methods, integrations and emails, keyed by settings tab.
	 *
	 * @return array<string, array>
	 */
	private function registered_objects() {
		$sources = array(
			'checkout'    => array( 'payment_gateways', 'payment_gateways' ),
			'shipping'    => array( 'shipping', 'get_shipping_methods' ),
			'integration' => array( 'integrations', 'get_integrations' ),
			'email'       => array( 'mailer', 'get_emails' ),
		);
		$objects = array();
		foreach ( $sources as $tab => $source ) {
			ob_start();
			try {
				$container       = 'integrations' === $source[0] ? WC()->integrations : WC()->{$source[0]}();
				$objects[ $tab ] = ( is_object( $container ) && method_exists( $container, $source[1] ) ) ? (array) $container->{$source[1]}() : array();
			} catch ( Throwable $e ) {
				$objects[ $tab ] = array();
			}
			ob_end_clean();
		}
		return $objects;
	}

	/**
	 * Display title of a gateway, shipping method, integration or email.
	 *
	 * @param object $item     WooCommerce object.
	 * @param string $fallback Used when the object has no title.
	 * @return string
	 */
	private static function object_title( $item, $fallback ) {
		$title = method_exists( $item, 'get_method_title' ) ? $item->get_method_title() : '';
		if ( '' === (string) $title && method_exists( $item, 'get_title' ) ) {
			$title = $item->get_title();
		}
		if ( '' === (string) $title ) {
			$title = isset( $item->method_title ) ? $item->method_title : ( isset( $item->title ) ? $item->title : $fallback );
		}
		return PSF_Context::clean( (string) $title );
	}
}
