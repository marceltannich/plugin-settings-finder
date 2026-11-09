<?php
/**
 * Detector contract.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One way of finding the admin pages that belong to plugins.
 *
 * Detectors run in order (see PSF_Scanner::detectors()) and add what they
 * find to the shared context. Later detectors can see what earlier ones
 * found, e.g. the code scan only looks at plugins nobody found pages for.
 * Add your own with the `psf_detectors` filter.
 */
interface PSF_Detector {

	/**
	 * Find pages and add them to the context.
	 *
	 * @param PSF_Context $context Shared build state.
	 */
	public function detect( PSF_Context $context );
}
