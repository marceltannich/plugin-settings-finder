<?php
/**
 * The admin bar dropdown: permissions, escaping, sorting, hiding, rebuilds.
 *
 * @package PluginSettingsFinder
 */

use PHPUnit\Framework\TestCase;

final class AdminBarTest extends TestCase {

	protected function setUp(): void {
		wp_set_current_user( 1 );
		delete_user_meta( 1, 'psf_sort' );
		remove_all_actions( 'shutdown' );
	}

	protected function tearDown(): void {
		delete_option( PSF_Admin_Bar::OPTION );
		delete_user_meta( 1, 'psf_sort' );
	}

	/**
	 * Store a hand-made, fresh index so add_nodes() does not rebuild it.
	 *
	 * @param array $plugins basename => record.
	 * @param array $extra   Override time/sig.
	 */
	private function store_index( array $plugins, array $extra = array() ) {
		update_option(
			PSF_Admin_Bar::OPTION,
			array_merge(
				array(
					'time'    => time(),
					'sig'     => PSF_Scanner::signature(),
					'plugins' => $plugins,
				),
				$extra
			),
			false
		);
	}

	/**
	 * Render the dropdown into a fresh admin bar.
	 *
	 * @return WP_Admin_Bar
	 */
	private function render() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		( new PSF_Admin_Bar() )->add_nodes( $bar );
		return $bar;
	}

	/**
	 * Titles of the plugin heading nodes, in order.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 * @return string[]
	 */
	private function plugin_titles( $bar ) {
		$titles = array();
		foreach ( (array) $bar->get_nodes() as $node ) {
			if ( isset( $node->meta['class'] ) && 0 === strpos( $node->meta['class'], 'psf-plugin' ) ) {
				$titles[] = wp_strip_all_tags( $node->title );
			}
		}
		return $titles;
	}

	private static function plugin( $name, array $entries = array(), array $usage = array() ) {
		return array(
			'name'    => $name,
			'entries' => $entries,
			'usage'   => $usage,
		);
	}

	public function test_nothing_for_users_without_capability() {
		$this->store_index( array( 'a/a.php' => self::plugin( 'Alpha' ) ) );
		wp_set_current_user( 0 );
		$this->assertNull( $this->render()->get_node( 'psf' ) );

		$subscriber = wp_insert_user(
			array(
				'user_login' => 'psf-sub-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );
		$this->assertNull( $this->render()->get_node( 'psf' ) );
		wp_delete_user( $subscriber );
	}

	public function test_plugin_names_labels_and_hints_are_escaped() {
		$evil = '<img src=x onerror=alert(1)>';
		$this->store_index(
			array(
				'evil/evil.php'   => self::plugin(
					$evil,
					array(
						array(
							'label' => $evil,
							'url'   => 'admin.php?page=x',
							'type'  => 'menu',
						),
					)
				),
				'quiet/quiet.php' => self::plugin(
					'Quiet',
					array(),
					array(
						'blocks'     => array( $evil ),
						'shortcodes' => array(),
						'widgets'    => array(),
						'readme'     => $evil,
						'mentions'   => array(),
					)
				),
			)
		);

		foreach ( (array) $this->render()->get_nodes() as $node ) {
			$this->assertStringNotContainsString( '<img', (string) $node->title, "node {$node->id}" );
		}
	}

	public function test_hidden_plugins_are_not_shown() {
		$this->store_index(
			array(
				'woocommerce/woocommerce.php'           => self::plugin( 'WooCommerce' ),
				'elementor/elementor.php'               => self::plugin( 'Elementor' ),
				'contact-form-7/wp-contact-form-7.php'  => self::plugin( 'Contact Form 7' ),
				'example/example.php'                   => self::plugin( 'Example' ),
			)
		);
		$this->assertSame( array( 'Example' ), $this->plugin_titles( $this->render() ) );

		add_filter( 'psf_hidden_plugins', '__return_empty_array' );
		$this->assertCount( 4, $this->plugin_titles( $this->render() ) );
		remove_filter( 'psf_hidden_plugins', '__return_empty_array' );
	}

	public function test_sort_orders() {
		$this->store_index(
			array(
				'b/b.php' => self::plugin( 'Bravo' ),
				'a/a.php' => self::plugin( 'alpha' ),
				'c/c.php' => self::plugin( 'Charlie' ),
			)
		);
		update_option( 'psf_activations', array( 'b/b.php' => time() - 60 ), false );

		$this->assertSame( array( 'alpha', 'Bravo 1 minute ago', 'Charlie' ), $this->plugin_titles( $this->render() ), 'A–Z is the default, case-insensitive' );

		update_user_meta( 1, 'psf_sort', 'za' );
		$this->assertSame( array( 'Charlie', 'Bravo 1 minute ago', 'alpha' ), $this->plugin_titles( $this->render() ) );

		update_user_meta( 1, 'psf_sort', 'recent' );
		$titles = $this->plugin_titles( $this->render() );
		$this->assertSame( 'Bravo 1 minute ago', $titles[0], 'recently activated on top' );

		update_user_meta( 1, 'psf_sort', '<script>' );
		$this->assertSame( 'alpha', $this->plugin_titles( $this->render() )[0], 'invalid value falls back to A–Z' );

		delete_option( 'psf_activations' );
	}

	public function test_plugin_without_pages_shows_hints() {
		$this->store_index(
			array(
				'q/q.php' => self::plugin(
					'Quiet',
					array(),
					array(
						'blocks'     => array( 'Hero' ),
						'shortcodes' => array( '[a]' ),
						'widgets'    => array(),
						'readme'     => 'Does a thing.',
						'mentions'   => array( 'works without configuration' ),
					)
				),
			)
		);
		$texts = array_map(
			static function ( $node ) {
				return wp_strip_all_tags( $node->title );
			},
			array_values( (array) $this->render()->get_nodes() )
		);
		$this->assertContains( 'No settings page found', $texts );
		$this->assertContains( 'Blocks: Hero open editor', $texts );
		$this->assertContains( 'Shortcodes: [a]', $texts );
		$this->assertContains( 'Readme mentions: works without configuration', $texts );
		$this->assertContains( '“Does a thing.”', $texts );
	}

	public function test_pcp_link_only_when_plugin_check_is_active() {
		$this->store_index( array( 'a/a.php' => self::plugin( 'Alpha' ) ) );
		$bar = $this->render();
		$pcp = $bar->get_node( 'psf-p-' . substr( md5( 'a/a.php' ), 0, 10 ) . '-pcp' );
		if ( defined( 'WP_PLUGIN_CHECK_MAIN_FILE' ) ) {
			$this->assertNotNull( $pcp );
			$this->assertStringContainsString( 'page=plugin-check&plugin=a%2Fa.php', $pcp->href );
		} else {
			$this->assertNull( $pcp );
		}
	}

	public function test_changed_plugin_set_rebuilds_immediately() {
		$this->store_index( array( 'gone/gone.php' => self::plugin( 'Gone' ) ), array( 'sig' => 'outdated' ) );
		$this->render();
		$index = PSF_Admin_Bar::stored_index();
		$this->assertArrayNotHasKey( 'gone/gone.php', $index['plugins'] );
		$this->assertSame( PSF_Scanner::signature(), $index['sig'] );
	}

	public function test_old_index_is_shown_and_rebuilt_after_the_response() {
		$this->store_index( array( 'kept/kept.php' => self::plugin( 'Kept' ) ), array( 'time' => time() - HOUR_IN_SECONDS ) );

		$this->assertSame( array( 'Kept' ), $this->plugin_titles( $this->render() ), 'renders the stored index without waiting' );
		$this->assertNotFalse( has_action( 'shutdown', array( 'PSF_Admin_Bar', 'rebuild_after_response' ) ) );

		PSF_Admin_Bar::rebuild_after_response();
		$this->assertArrayNotHasKey( 'kept/kept.php', PSF_Admin_Bar::stored_index()['plugins'] );
		$this->assertFalse( get_transient( 'psf_rebuilding' ), 'lock released' );
	}

	public function test_no_other_plugins_message() {
		$this->store_index( array() );
		$this->assertNotNull( $this->render()->get_node( 'psf-no-plugins' ) );
	}
}
