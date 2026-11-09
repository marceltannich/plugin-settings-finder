<?php
/**
 * Plugin Name: Example Without Settings
 * Description: Fixture. No admin page at all; readme says so.
 */

add_filter( 'the_content', function ( $content ) { return $content; } );
