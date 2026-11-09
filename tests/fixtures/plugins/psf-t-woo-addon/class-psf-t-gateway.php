<?php
/**
 * Fixture payment gateway (its own file, so attribution must use the class file).
 */
class Psf_T_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id           = 'psf_example_pay';
		$this->method_title = 'Example Pay';
		$this->init_settings();
	}
}
