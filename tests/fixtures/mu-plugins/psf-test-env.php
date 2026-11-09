<?php
/**
 * Plugin Name: PSF test environment
 * Description: Test site only. Keeps WooCommerce from redirecting to its setup wizard, which would abort WP-CLI runs.
 */

add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );
add_filter( 'woocommerce_enable_setup_wizard', '__return_false' );
