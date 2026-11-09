<?php
/**
 * Plugin Name: Example Settings
 * Description: Fixture. A page under Settings, a "Settings" row link, an external docs link (ignored), and a shared helper another fixture uses to register its menu.
 */

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Example Options', 'Example Options', 'manage_options', 'example-options', 'psf_t_render' );
	}
);

function psf_t_render() {
	echo '<h1>Example</h1>';
}

add_filter(
	'plugin_action_links_psf-t-settings/psf-t-settings.php',
	function ( $links ) {
		$links[] = '<a href="' . admin_url( 'options-general.php?page=example-options' ) . '">Settings</a>';
		$links[] = '<a href="https://example.com/docs">Docs</a>';
		return $links;
	}
);

// Shared framework-style helper: the menu callback lives in this plugin, but another plugin calls it.
function psf_t_shared_register( $title, $slug ) {
	add_action(
		'admin_menu',
		function () use ( $title, $slug ) {
			add_options_page( $title, $title, 'manage_options', $slug, 'psf_t_render' );
		}
	);
}
