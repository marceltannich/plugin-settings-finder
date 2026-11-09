<?php
/**
 * Builds the real index against the fixture plugins (tests/fixtures/plugins).
 *
 * @package PluginSettingsFinder
 */

use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase {

	/**
	 * Index built once for all tests.
	 *
	 * @var array
	 */
	private static $index;

	public static function setUpBeforeClass(): void {
		delete_option( PSF_Detector_Code::CACHE );
		delete_option( PSF_Detector_Usage::CACHE );
		self::$index = ( new PSF_Scanner() )->build();
	}

	/**
	 * Index record of a fixture plugin.
	 *
	 * @param string $slug Fixture folder name.
	 * @return array
	 */
	private function plugin( $slug ) {
		$basename = $slug . '/' . $slug . '.php';
		$this->assertArrayHasKey( $basename, self::$index['plugins'], "{$slug} is active and indexed" );
		return self::$index['plugins'][ $basename ];
	}

	/**
	 * Entry of a plugin by label.
	 *
	 * @param string $slug  Fixture folder name.
	 * @param string $label Breadcrumb.
	 * @return array
	 */
	private function entry( $slug, $label ) {
		foreach ( $this->plugin( $slug )['entries'] as $entry ) {
			if ( $entry['label'] === $label ) {
				return $entry;
			}
		}
		$this->fail( "{$slug} has no entry '{$label}'. Found: " . implode( ', ', array_column( $this->plugin( $slug )['entries'], 'label' ) ) );
	}

	public function test_index_shape() {
		$this->assertIsInt( self::$index['time'] );
		$this->assertSame( PSF_Scanner::signature(), self::$index['sig'] );
		$this->assertArrayNotHasKey( plugin_basename( PSF_PLUGIN_FILE ), self::$index['plugins'], 'never lists itself' );
	}

	public function test_page_under_settings() {
		$entry = $this->entry( 'psf-t-settings', 'Settings › Example Options' );
		$this->assertSame( 'options-general.php?page=example-options', $entry['url'] );
		$this->assertSame( 'menu', $entry['type'] );
	}

	public function test_row_link_to_the_same_page_is_merged_and_external_link_ignored() {
		$entries = $this->plugin( 'psf-t-settings' )['entries'];
		$urls    = array_column( $entries, 'url' );
		$this->assertSame( 1, count( array_keys( $urls, 'options-general.php?page=example-options', true ) ), 'menu page and "Settings" row link are one entry' );
		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( 'example.com', $url );
		}
	}

	public function test_page_under_media_and_tools() {
		$this->assertSame( 'upload.php?page=media-tools', $this->entry( 'psf-t-media', 'Media › Media Tools' )['url'] );
		$this->assertSame( 'tools.php?page=example-utility', $this->entry( 'psf-t-tools', 'Tools › Utility' )['url'], 'class method callback' );
	}

	public function test_top_level_menu_strips_count_bubble_and_sorts_options_first() {
		$entries = $this->plugin( 'psf-t-top' )['entries'];
		$this->assertSame( 'Example › Options', $entries[0]['label'], 'settings-looking page first' );
		$this->assertSame( 'admin.php?page=example-top', $this->entry( 'psf-t-top', 'Example' )['url'] );
	}

	public function test_hidden_page() {
		$entry = $this->entry( 'psf-t-hidden', 'Welcome' );
		$this->assertSame( 'admin.php?page=example-welcome', $entry['url'] );
		$this->assertSame( 'hidden', $entry['type'] );
	}

	public function test_post_type_screens_belong_to_the_registering_plugin() {
		$this->assertSame( 'edit.php?post_type=psf_book', $this->entry( 'psf-t-cpt', 'Books' )['url'] );
	}

	public function test_code_scan_finds_page_registered_through_another_plugins_helper() {
		$entry = $this->entry( 'psf-t-framework-user', 'Settings › Framework Page' );
		$this->assertSame( 'code', $entry['type'] );
		$this->assertSame( 'options-general.php?page=framework-page', $entry['url'] );
	}

	public function test_plugin_without_pages_explains_itself_from_readme() {
		$plugin = $this->plugin( 'psf-t-none' );
		$this->assertSame( array(), $plugin['entries'] );
		$this->assertStringStartsWith( 'Silently fixes emoji output.', $plugin['usage']['readme'] );
		$this->assertContains( 'works without configuration', $plugin['usage']['mentions'] );
	}

	public function test_block_plugin() {
		$usage = $this->plugin( 'psf-t-block' )['usage'];
		$this->assertSame( array( 'Example Quote' ), $usage['blocks'] );
		$this->assertNotContains( 'blocks', $usage['mentions'], 'no readme hint for what is already proven' );
	}

	public function test_shortcode_plugin() {
		$this->assertEqualsCanonicalizing( array( '[current_year]', '[copyright]' ), $this->plugin( 'psf-t-shortcode' )['usage']['shortcodes'] );
	}

	public function test_widget_plugin() {
		$this->assertSame( array( 'Example Box' ), $this->plugin( 'psf-t-widget' )['usage']['widgets'] );
	}

	public function test_file_results_are_cached_per_version() {
		$cache = get_option( PSF_Detector_Usage::CACHE );
		$this->assertArrayHasKey( 'psf-t-block/psf-t-block.php', $cache );
		$this->assertSame( '', $cache['psf-t-block/psf-t-block.php']['v'], 'fixtures have no version header' );
	}

	public function test_woocommerce_tab_section_and_gateway() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed (WITH_WOO=1 tests/bin/install-test-site.sh).' );
		}
		$this->assertSame( 'admin.php?page=wc-settings&tab=example_tab', $this->entry( 'psf-t-woo-addon', 'WooCommerce › Settings › Example Tab' )['url'] );
		$this->assertSame( 'admin.php?page=wc-settings&tab=products&section=example_section', $this->entry( 'psf-t-woo-addon', 'WooCommerce › Settings › Products › Example Section' )['url'] );

		$gateway = null;
		foreach ( $this->plugin( 'psf-t-woo-addon' )['entries'] as $entry ) {
			if ( false !== strpos( $entry['url'], 'section=psf_example_pay' ) ) {
				$gateway = $entry;
			}
		}
		$this->assertNotNull( $gateway, 'gateway in its own class file is attributed to the add-on' );
		$this->assertStringEndsWith( '› Example Pay', $gateway['label'] );
	}

	public function test_woocommerce_own_tabs_are_not_listed_as_add_on_pages() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed.' );
		}
		foreach ( $this->plugin( 'psf-t-woo-addon' )['entries'] as $entry ) {
			$this->assertStringNotContainsString( 'tab=general', $entry['url'] );
		}
	}

	public function test_custom_detector_via_filter() {
		$detector = new class() implements PSF_Detector {
			public function detect( PSF_Context $context ) {
				$context->add_entry( 'psf-t-none/psf-t-none.php', 'Custom › Page', 'admin.php?page=custom', 'menu' );
			}
		};
		$add = static function ( $detectors ) use ( $detector ) {
			$detectors[] = $detector;
			$detectors[] = 'not a detector';
			return $detectors;
		};
		add_filter( 'psf_detectors', $add );
		$index = ( new PSF_Scanner() )->build();
		remove_filter( 'psf_detectors', $add );

		$this->assertSame( 'Custom › Page', $index['plugins']['psf-t-none/psf-t-none.php']['entries'][0]['label'] );
	}
}
