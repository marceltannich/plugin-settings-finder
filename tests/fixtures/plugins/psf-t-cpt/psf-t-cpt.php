<?php
/**
 * Plugin Name: Example Content Types
 * Description: Fixture. A custom post type with its own menu.
 */

add_action(
	'init',
	function () {
		register_post_type( 'psf_book', array( 'label' => 'Books', 'public' => true, 'show_ui' => true ) );
	}
);
