<?php
/**
 * Plugin Name: Example Media Tools
 * Description: Fixture. A page under Media.
 */

add_action(
	'admin_menu',
	function () {
		add_media_page( 'Media Tools', 'Media Tools', 'manage_options', 'media-tools', function () {} );
	}
);
