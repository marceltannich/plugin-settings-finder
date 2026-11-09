<?php
/**
 * Plugin Name: Example Top Menu
 * Description: Fixture. A top-level menu with a count bubble and an "Options" submenu.
 */

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Example', 'Example <span class="update-plugins count-3"><span class="plugin-count">3</span></span>', 'manage_options', 'example-top', function () {}, 'dashicons-star-filled', 2 );
		add_submenu_page( 'example-top', 'Example Options', 'Options', 'manage_options', 'example-top-options', function () {} );
	}
);
