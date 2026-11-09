<?php
/**
 * Plugin Name: Example Block
 * Description: Fixture. Registers a block from block.json; no admin page.
 */

add_action(
	'init',
	function () {
		register_block_type( __DIR__ . '/build/quote' );
	}
);
