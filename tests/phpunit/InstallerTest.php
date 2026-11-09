<?php
/**
 * "Move to must-use": link, permissions and safeguards.
 * The actual move is covered end to end by tests/bin/smoke.sh.
 *
 * @package PluginSettingsFinder
 */

use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase {

	/**
	 * Folder the installer would write to.
	 *
	 * @var string
	 */
	private $target;

	protected function setUp(): void {
		wp_set_current_user( 1 );
		$this->target = WPMU_PLUGIN_DIR . '/' . PSF_Installer::FOLDER;
	}

	protected function tearDown(): void {
		if ( is_dir( $this->target . '/.git' ) ) {
			rmdir( $this->target . '/.git' );
		}
		if ( is_dir( $this->target ) ) {
			rmdir( $this->target );
		}
	}

	public function test_runs_as_regular_plugin_in_the_test_site() {
		$this->assertFalse( PSF_Installer::is_must_use() );
	}

	public function test_link_for_admins_points_to_admin_post_with_nonce() {
		$links = PSF_Installer::action_link( array() );
		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'Move to must-use', $links[0] );
		$this->assertMatchesRegularExpression( '#admin-post\.php\?action=psf_install_mu&(amp;|\#038;)?_wpnonce=[a-f0-9]{10}#', $links[0] );
	}

	public function test_no_link_for_users_who_cannot_manage_plugins() {
		$editor = wp_insert_user(
			array(
				'user_login' => 'psf-editor-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $editor );
		$this->assertSame( array( 'x' ), PSF_Installer::action_link( array( 'x' ) ) );
		wp_delete_user( $editor );
	}

	public function test_refuses_to_overwrite_a_git_checkout() {
		wp_mkdir_p( $this->target . '/.git' );
		$result = PSF_Installer::install( true );
		$this->assertWPError( $result, 'psf_git' );
	}

	public function test_does_not_overwrite_an_existing_copy_without_force() {
		wp_mkdir_p( $this->target );
		$result = PSF_Installer::install( false );
		$this->assertWPError( $result, 'psf_exists' );
	}

	/**
	 * Assert a WP_Error with a given code.
	 *
	 * @param mixed  $result Value.
	 * @param string $code   Expected error code.
	 */
	private function assertWPError( $result, $code ) {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}
