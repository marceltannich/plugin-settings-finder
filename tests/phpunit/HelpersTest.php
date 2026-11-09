<?php
/**
 * Pure helper functions.
 *
 * @package PluginSettingsFinder
 */

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase {

	/**
	 * @dataProvider screen_keys
	 */
	public function test_screen_key_treats_same_page_as_one_screen( $a, $b, $same ) {
		$this->assertSame( $same, PSF_Context::screen_key( $a ) === PSF_Context::screen_key( $b ) );
	}

	public function screen_keys() {
		return array(
			'parent file does not matter'  => array( 'options-general.php?page=foo', 'admin.php?page=foo', true ),
			'tab makes it different'       => array( 'admin.php?page=wc-settings&tab=a', 'admin.php?page=wc-settings&tab=b', false ),
			'section is case-insensitive'  => array( 'admin.php?page=wc-settings&tab=checkout&section=Foo', 'admin.php?page=wc-settings&tab=checkout&section=foo', true ),
			'non-page URLs compare as-is'  => array( 'edit.php?post_type=book', 'edit.php?post_type=book', true ),
			'different post types differ'  => array( 'edit.php?post_type=book', 'edit.php?post_type=film', false ),
		);
	}

	public function test_clean_strips_count_bubbles_and_markup() {
		$this->assertSame( 'Comments', PSF_Context::clean( 'Comments <span class="awaiting-mod count-3"><span class="pending-count">3</span></span>' ) );
		$this->assertSame( 'Foo & Bar', PSF_Context::clean( '<b>Foo</b> &amp;   Bar' ) );
		$this->assertSame( '', PSF_Context::clean( '' ) );
	}

	/**
	 * @dataProvider admin_urls
	 */
	public function test_admin_relative( $href, $expected ) {
		$this->assertSame( $expected, PSF_Context::admin_relative( $href ) );
	}

	public function admin_urls() {
		return array(
			'absolute admin URL'   => array( admin_url( 'options-general.php?page=x' ), 'options-general.php?page=x' ),
			'relative file'        => array( 'tools.php?page=y', 'tools.php?page=y' ),
			'root-relative'        => array( '/wp-admin/upload.php?page=z', 'upload.php?page=z' ),
			'external site'        => array( 'https://example.com/wp-admin/options.php', null ),
			'javascript'           => array( 'javascript:alert(1)', null ),
			'data URL'             => array( 'data:text/html,hi', null ),
			'anchor'               => array( '#top', null ),
			'empty'                => array( '', null ),
			'front end path'       => array( '/shop/', null ),
		);
	}

	public function test_action_link_parse_keeps_admin_pages_only() {
		$settings = PSF_Detector_Action_Links::parse( '<a href="' . admin_url( 'options-general.php?page=x' ) . '">Settings</a>' );
		$this->assertSame(
			array(
				'text' => 'Settings',
				'url'  => 'options-general.php?page=x',
			),
			$settings
		);
		$this->assertNull( PSF_Detector_Action_Links::parse( '<a href="https://example.com/docs">Docs</a>' ), 'external link' );
		$this->assertNull( PSF_Detector_Action_Links::parse( '<a href="' . admin_url( 'plugins.php?action=deactivate&plugin=x&_wpnonce=1' ) . '">Deactivate</a>' ), 'action link' );
		$this->assertNull( PSF_Detector_Action_Links::parse( '<a href="' . admin_url( 'tools.php?page=plugin-check&plugin=x' ) . '">Check this plugin</a>' ), 'Plugin Check row link' );
		$this->assertNull( PSF_Detector_Action_Links::parse( array( 'not a string' ) ) );
	}

	public function test_slugs_in_code() {
		$code  = "<?php add_options_page( 'Title', 'Menu', 'manage_options', 'my-slug', 'cb' );\n";
		$code .= "\$url = admin_url( 'admin.php?page=other-slug&tab=x' );\n";
		$slugs = PSF_Detector_Code::slugs_in_code( $code );
		$this->assertContains( 'my-slug', $slugs );
		$this->assertContains( 'other-slug', $slugs );
		$this->assertSame( array(), PSF_Detector_Code::slugs_in_code( '<?php echo "no menus";' ) );
	}

	public function test_blocks_in_code() {
		$this->assertSame( array( 'acme/hero' ), PSF_Detector_Usage::blocks_in_code( "register_block_type( 'acme/hero', array() ); register_block_type( \"acme/hero\" );" ) );
		$this->assertSame( array(), PSF_Detector_Usage::blocks_in_code( 'register_block_type( __DIR__ . "/build" );' ) );
	}

	public function test_parse_readme_short_description_and_hints() {
		$readme = "=== Thing ===\nContributors: a\nTags: block\nStable tag: 1.0\n\nAdds a shortcode and a widget. Works out of the box.\n\n== Description ==\nMore.";
		$parsed = PSF_Detector_Usage::parse_readme( $readme );
		$this->assertSame( 'Adds a shortcode and a widget. Works out of the box.', $parsed['readme'] );
		$this->assertEqualsCanonicalizing( array( 'blocks', 'shortcodes', 'widgets', 'auto' ), $parsed['mentions'] );
	}

	public function test_parse_readme_markdown_and_empty() {
		$parsed = PSF_Detector_Usage::parse_readme( "# Thing\n\n**Tags:** cli\n\nA WP-CLI command.\n" );
		$this->assertSame( 'A WP-CLI command.', $parsed['readme'] );
		$this->assertContains( 'cli', $parsed['mentions'] );
		$this->assertSame(
			array(
				'readme'   => '',
				'mentions' => array(),
			),
			PSF_Detector_Usage::parse_readme( '' )
		);
	}

	public function test_sort_entries_puts_settings_first_then_by_rank() {
		$sorted = PSF_Scanner::sort_entries(
			array(
				array( 'label' => 'Code page', 'url' => 'admin.php?page=c', 'type' => 'code', 'seq' => 0 ),
				array( 'label' => 'Reports', 'url' => 'admin.php?page=r', 'type' => 'menu', 'seq' => 1 ),
				array( 'label' => 'Settings › Foo', 'url' => 'options-general.php?page=f', 'type' => 'menu', 'seq' => 2 ),
				array( 'label' => 'Hidden settings', 'url' => 'admin.php?page=h', 'type' => 'hidden', 'seq' => 3 ),
			)
		);
		$this->assertSame( array( 'Settings › Foo', 'Reports', 'Hidden settings', 'Code page' ), array_column( $sorted, 'label' ) );
		$this->assertArrayNotHasKey( 'seq', $sorted[0] );
	}
}
