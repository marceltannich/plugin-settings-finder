<?php
/**
 * Plugin Name: Example Shortcodes
 * Description: Fixture. Two shortcodes (closure and named function); no admin page.
 */

add_shortcode( 'current_year', function () { return gmdate( 'Y' ); } );
add_shortcode( 'copyright', 'psf_t_copyright' );
function psf_t_copyright() {
	return '©';
}
