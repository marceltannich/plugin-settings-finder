<?php
/**
 * Plugin Name: Example Framework User
 * Description: Fixture. Registers its page through a helper in another plugin, so only the code scan can attribute it.
 */

add_action(
	'plugins_loaded',
	function () {
		if ( function_exists( 'psf_t_shared_register' ) ) {
			psf_t_shared_register( 'Framework Page', 'framework-page' );
		}
	}
);
// The slug the code scan looks for: add_options_page( 'Framework Page', 'Framework Page', 'manage_options', 'framework-page' )
