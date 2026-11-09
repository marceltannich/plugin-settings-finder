<?php
/**
 * Plugin Name: Example Utility
 * Description: Fixture. A page under Tools, registered from a class method.
 */

class Psf_T_Utility {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}
	public function menu() {
		add_management_page( 'Utility', 'Utility', 'manage_options', 'example-utility', array( $this, 'render' ) );
	}
	public function render() {
		echo 'Utility';
	}
}
new Psf_T_Utility();
