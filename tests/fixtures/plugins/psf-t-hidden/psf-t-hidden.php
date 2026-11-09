<?php
/**
 * Plugin Name: Example Onboarding
 * Description: Fixture. A hidden welcome page without a menu item.
 */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( '', 'Welcome', 'Welcome', 'manage_options', 'example-welcome', function () {} );
	}
);
