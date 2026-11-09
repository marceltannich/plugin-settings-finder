<?php
/**
 * Plugin Name: Example Shop Add-on
 * Description: Fixture. A WooCommerce settings tab, a section in the Products tab and a payment gateway. Needs WooCommerce.
 */

add_filter(
	'woocommerce_settings_tabs_array',
	function ( $tabs ) {
		$tabs['example_tab'] = 'Example Tab';
		return $tabs;
	},
	50
);
add_filter(
	'woocommerce_get_sections_products',
	function ( $sections ) {
		$sections['example_section'] = 'Example Section';
		return $sections;
	}
);
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		require_once __DIR__ . '/class-psf-t-gateway.php';
		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'Psf_T_Gateway';
				return $gateways;
			}
		);
	},
	20
);
