<?php
/**
 * Plugin Name: Example Widget
 * Description: Fixture. A classic widget; no admin page.
 */

class Psf_T_Example_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'psf_example', 'Example Box' );
	}
	public function widget( $args, $instance ) {
		echo 'Example';
	}
}
add_action( 'widgets_init', function () { register_widget( 'Psf_T_Example_Widget' ); } );
