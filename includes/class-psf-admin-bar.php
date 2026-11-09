<?php
/**
 * The admin bar dropdown.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the "Plugin Settings Finder" dropdown and keeps the index fresh.
 */
final class PSF_Admin_Bar {

	const OPTION     = 'psf_index';
	const MAX_AGE    = 600;
	const RECENT_FOR = 14 * DAY_IN_SECONDS;

	/**
	 * Rebuild requested via the "Rescan" link.
	 *
	 * @var bool
	 */
	private $force = false;

	/**
	 * Hook everything up.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_force' ) );
		add_action( 'init', array( $this, 'maybe_set_sort' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Capability needed to see the dropdown.
	 *
	 * @return string
	 */
	private function capability() {
		return (string) apply_filters( 'psf_capability', 'activate_plugins' );
	}

	/**
	 * Honour the "Rescan" link.
	 */
	public function maybe_force() {
		if ( isset( $_GET['psf_rescan'], $_GET['psf_nonce'] )
			&& current_user_can( $this->capability() )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_GET['psf_nonce'] ) ), 'psf_rescan' ) ) {
			$this->force = true;
		}
	}

	/**
	 * Current user's sort order: "alpha" (default) or "recent".
	 *
	 * @return string
	 */
	private function sort_order() {
		$sort = get_user_meta( get_current_user_id(), 'psf_sort', true );
		return in_array( $sort, array( 'za', 'recent' ), true ) ? $sort : 'alpha';
	}

	/**
	 * Save the sort order chosen in the dropdown, then drop the query args.
	 */
	public function maybe_set_sort() {
		if ( ! isset( $_GET['psf_sort'], $_GET['psf_nonce'] ) || ! is_user_logged_in() || ! current_user_can( $this->capability() ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['psf_nonce'] ) ), 'psf_sort' ) ) {
			return;
		}
		$sort = sanitize_key( wp_unslash( $_GET['psf_sort'] ) );
		$sort = in_array( $sort, array( 'za', 'recent' ), true ) ? $sort : 'alpha';
		update_user_meta( get_current_user_id(), 'psf_sort', $sort );
		wp_safe_redirect( remove_query_arg( array( 'psf_sort', 'psf_nonce' ) ) );
		exit;
	}

	/**
	 * Current index; rebuilt on admin screens when stale.
	 *
	 * @return array|null
	 */
	private function index() {
		$index = get_option( self::OPTION );
		$index = is_array( $index ) && isset( $index['plugins'] ) ? $index : null;

		if ( ! is_admin() || empty( $GLOBALS['menu'] ) ) {
			return $index;
		}

		if ( $this->force || null === $index || $index['sig'] !== PSF_Scanner::signature() || time() - $index['time'] > self::MAX_AGE ) {
			$index = ( new PSF_Scanner() )->build();
			update_option( self::OPTION, $index, false );
		}
		return $index;
	}

	/**
	 * Add the dropdown.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public function add_nodes( $bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( $this->capability() ) ) {
			return;
		}

		$index = $this->index();
		$times = get_option( 'psf_activations', array() );
		$times = is_array( $times ) ? $times : array();

		$bar->add_node(
			array(
				'id'    => 'psf',
				'title' => '<span class="ab-icon dashicons dashicons-admin-settings" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Plugin Settings Finder', 'plugin-settings-finder' ) . '</span>',
				'href'  => false,
				'meta'  => array( 'class' => 'psf-root' ),
			)
		);

		if ( null === $index ) {
			$bar->add_node(
				array(
					'parent' => 'psf',
					'id'     => 'psf-empty',
					'title'  => esc_html__( 'Open any admin screen once to build the list.', 'plugin-settings-finder' ),
					'href'   => admin_url(),
				)
			);
			return;
		}

		$bar->add_node(
			array(
				'parent' => 'psf',
				'id'     => 'psf-filter',
				'title'  => '<input type="search" class="psf-filter-input" placeholder="' . esc_attr__( 'Filter plugins…', 'plugin-settings-finder' ) . '" aria-label="' . esc_attr__( 'Filter plugins', 'plugin-settings-finder' ) . '" />',
				'meta'   => array( 'class' => 'psf-filter' ),
			)
		);

		// Always-installed plugins are hidden; matched by folder so Pro/renamed main files still match.
		$hidden = (array) apply_filters( 'psf_hidden_plugins', array( 'elementor', 'woocommerce', 'contact-form-7' ) );
		foreach ( array_keys( $index['plugins'] ) as $basename ) {
			if ( in_array( strtok( $basename, '/' ), $hidden, true ) ) {
				unset( $index['plugins'][ $basename ] );
			}
		}

		if ( ! $index['plugins'] ) {
			$bar->add_node(
				array(
					'parent' => 'psf',
					'id'     => 'psf-no-plugins',
					'title'  => esc_html__( 'No other active plugins to list.', 'plugin-settings-finder' ),
					'meta'   => array( 'class' => 'psf-entry psf-none' ),
				)
			);
		}

		uasort(
			$index['plugins'],
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		$sort = $this->sort_order();
		$bar->add_node(
			array(
				'parent' => 'psf',
				'id'     => 'psf-sort',
				'title'  => esc_html__( 'Sort:', 'plugin-settings-finder' ) . ' '
					. $this->sort_link( 'alpha', __( 'A–Z', 'plugin-settings-finder' ), $sort )
					. ' · '
					. $this->sort_link( 'za', __( 'Z–A', 'plugin-settings-finder' ), $sort )
					. ' · '
					. $this->sort_link( 'recent', __( 'Recently activated first', 'plugin-settings-finder' ), $sort ),
				'meta'   => array( 'class' => 'psf-sort' ),
			)
		);

		// "recent" mode: recently activated on top, newest first; the rest A–Z.
		// "alpha" / "za" mode: one sorted list, recent plugins keep their "x ago" badge.
		$recent = array();
		foreach ( $index['plugins'] as $basename => $plugin ) {
			if ( isset( $times[ $basename ] ) && time() - $times[ $basename ] < self::RECENT_FOR ) {
				$recent[ $basename ] = $times[ $basename ];
			}
		}
		arsort( $recent );
		$recent = array_slice( $recent, 0, 5, true );

		if ( 'za' === $sort ) {
			$index['plugins'] = array_reverse( $index['plugins'], true );
		}

		if ( 'recent' !== $sort ) {
			foreach ( $index['plugins'] as $basename => $plugin ) {
				$this->plugin_nodes( $bar, $basename, $plugin, isset( $recent[ $basename ] ) ? $recent[ $basename ] : null );
			}
			$recent = null;
		}

		if ( null !== $recent ) {
			if ( $recent ) {
				$this->section( $bar, 'psf-s-recent', __( 'Recently activated', 'plugin-settings-finder' ) );
				foreach ( array_keys( $recent ) as $basename ) {
					$this->plugin_nodes( $bar, $basename, $index['plugins'][ $basename ], $times[ $basename ] );
				}
				$this->section( $bar, 'psf-s-all', __( 'All active plugins', 'plugin-settings-finder' ) );
			}

			foreach ( $index['plugins'] as $basename => $plugin ) {
				if ( ! isset( $recent[ $basename ] ) ) {
					$this->plugin_nodes( $bar, $basename, $plugin, null );
				}
			}
		}

		$rescan = is_admin()
			? add_query_arg(
				array(
					'psf_rescan' => '1',
					'psf_nonce'  => wp_create_nonce( 'psf_rescan' ),
				)
			)
			: add_query_arg(
				array(
					'psf_rescan' => '1',
					'psf_nonce'  => wp_create_nonce( 'psf_rescan' ),
				),
				admin_url( 'plugins.php' )
			);

		$bar->add_group(
			array(
				'parent' => 'psf',
				'id'     => 'psf-meta',
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'psf-meta',
				'id'     => 'psf-rescan',
				'title'  => esc_html(
					sprintf(
						/* translators: %s: human time difference, e.g. "3 mins". */
						__( 'Rescan now (last scan %s ago)', 'plugin-settings-finder' ),
						human_time_diff( $index['time'] )
					)
				),
				'href'   => $rescan,
			)
		);
	}

	/**
	 * One option of the sort switch; the active one is plain text.
	 *
	 * @param string $value   alpha|recent.
	 * @param string $label   Link text.
	 * @param string $current Active sort order.
	 * @return string HTML.
	 */
	private function sort_link( $value, $label, $current ) {
		if ( $value === $current ) {
			return '<strong class="psf-sort-on">' . esc_html( $label ) . '</strong>';
		}
		$url = add_query_arg(
			array(
				'psf_sort'  => $value,
				'psf_nonce' => wp_create_nonce( 'psf_sort' ),
			)
		);
		return '<a class="psf-sort-link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * What a plugin without admin pages adds instead (blocks, shortcodes,
	 * widgets) and what its readme says about itself.
	 *
	 * @param WP_Admin_Bar $bar   Admin bar.
	 * @param string       $id    Plugin node id.
	 * @param array        $usage Usage record from the scanner.
	 */
	private function usage_nodes( $bar, $id, array $usage ) {
		$lines = array();
		if ( ! empty( $usage['blocks'] ) ) {
			$lines[] = array(
				/* translators: %s: comma-separated block titles. */
				sprintf( __( 'Blocks: %s', 'plugin-settings-finder' ), $this->short_list( $usage['blocks'] ) ),
				admin_url( 'post-new.php?post_type=page' ),
				__( 'open editor', 'plugin-settings-finder' ),
			);
		}
		if ( ! empty( $usage['shortcodes'] ) ) {
			$lines[] = array(
				/* translators: %s: list of shortcodes. */
				sprintf( __( 'Shortcodes: %s', 'plugin-settings-finder' ), $this->short_list( $usage['shortcodes'], ' ' ) ),
				'',
				'',
			);
		}
		if ( ! empty( $usage['widgets'] ) ) {
			$lines[] = array(
				/* translators: %s: comma-separated widget names. */
				sprintf( __( 'Widgets: %s', 'plugin-settings-finder' ), $this->short_list( $usage['widgets'] ) ),
				admin_url( wp_is_block_theme() ? 'site-editor.php' : 'widgets.php' ),
				'',
			);
		}
		if ( ! empty( $usage['mentions'] ) ) {
			$lines[] = array(
				/* translators: %s: comma-separated hints such as "blocks, shortcodes". */
				sprintf( __( 'Readme mentions: %s', 'plugin-settings-finder' ), implode( ', ', $usage['mentions'] ) ),
				'',
				'',
			);
		}
		if ( ! empty( $usage['readme'] ) ) {
			$lines[] = array( '“' . $usage['readme'] . '”', '', '' );
		}

		foreach ( $lines as $i => $line ) {
			$title = esc_html( $line[0] );
			if ( '' !== $line[2] ) {
				$title .= ' <span class="psf-badge">' . esc_html( $line[2] ) . '</span>';
			}
			$bar->add_node(
				array(
					'parent' => 'psf',
					'id'     => $id . '-use-' . $i,
					'title'  => $title,
					'href'   => '' !== $line[1] ? $line[1] : false,
					'meta'   => array( 'class' => 'psf-entry psf-info' ),
				)
			);
		}
	}

	/**
	 * First few items of a list, "+N more" for the rest.
	 *
	 * @param string[] $items Items.
	 * @param string   $glue  Separator.
	 * @return string
	 */
	private function short_list( array $items, $glue = ', ' ) {
		$shown = array_slice( $items, 0, 4 );
		$more  = count( $items ) - count( $shown );
		/* translators: %d: number of further items. */
		return implode( $glue, $shown ) . ( $more > 0 ? ' ' . sprintf( __( '+%d more', 'plugin-settings-finder' ), $more ) : '' );
	}

	/**
	 * A section heading.
	 *
	 * @param WP_Admin_Bar $bar   Admin bar.
	 * @param string       $id    Node id.
	 * @param string       $title Heading.
	 */
	private function section( $bar, $id, $title ) {
		$bar->add_node(
			array(
				'parent' => 'psf',
				'id'     => $id,
				'title'  => esc_html( $title ),
				'meta'   => array( 'class' => 'psf-section' ),
			)
		);
	}

	/**
	 * One plugin heading plus its pages.
	 *
	 * @param WP_Admin_Bar $bar       Admin bar.
	 * @param string       $basename  Plugin basename.
	 * @param array        $plugin    Index record.
	 * @param int|null     $activated Activation timestamp for recent plugins.
	 */
	private function plugin_nodes( $bar, $basename, array $plugin, $activated ) {
		$id      = 'psf-p-' . substr( md5( $basename ), 0, 10 );
		$entries = $plugin['entries'];
		$badges  = array(
			'hidden' => __( 'hidden page', 'plugin-settings-finder' ),
			'link'   => __( 'Plugins screen link', 'plugin-settings-finder' ),
			'code'   => __( 'found in code', 'plugin-settings-finder' ),
		);

		$title = esc_html( $plugin['name'] );
		if ( $activated ) {
			$title .= ' <span class="psf-badge psf-new">' . esc_html(
				sprintf(
					/* translators: %s: human time difference. */
					__( '%s ago', 'plugin-settings-finder' ),
					human_time_diff( $activated )
				)
			) . '</span>';
		}

		$bar->add_node(
			array(
				'parent' => 'psf',
				'id'     => $id,
				'title'  => $title,
				'href'   => $entries ? admin_url( $entries[0]['url'] ) : false,
				'meta'   => array(
					'class' => 'psf-plugin' . ( $entries ? '' : ' psf-plugin-empty' ),
					'title' => $basename,
				),
			)
		);

		if ( ! $entries ) {
			$bar->add_node(
				array(
					'parent' => 'psf',
					'id'     => $id . '-none',
					'title'  => esc_html__( 'No settings page found', 'plugin-settings-finder' ),
					'meta'   => array( 'class' => 'psf-entry psf-none' ),
				)
			);
			$this->usage_nodes( $bar, $id, isset( $plugin['usage'] ) ? (array) $plugin['usage'] : array() );
		}

		foreach ( $entries as $i => $entry ) {
			$title = esc_html( $entry['label'] );
			if ( isset( $badges[ $entry['type'] ] ) ) {
				$title .= ' <span class="psf-badge">' . esc_html( $badges[ $entry['type'] ] ) . '</span>';
			}
			$bar->add_node(
				array(
					'parent' => 'psf',
					'id'     => $id . '-' . $i,
					'title'  => $title,
					'href'   => admin_url( $entry['url'] ),
					'meta'   => array( 'class' => 'psf-entry' ),
				)
			);
		}

		// Plugin Check (PCP) shortcut, visually set apart from the settings pages.
		if ( defined( 'WP_PLUGIN_CHECK_MAIN_FILE' ) && plugin_basename( WP_PLUGIN_CHECK_MAIN_FILE ) !== $basename ) {
			$bar->add_node(
				array(
					'parent' => 'psf',
					'id'     => $id . '-pcp',
					'title'  => '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Check with PCP', 'plugin-settings-finder' ),
					'href'   => admin_url( 'tools.php?page=plugin-check&plugin=' . rawurlencode( $basename ) ),
					'meta'   => array( 'class' => 'psf-entry psf-pcp' ),
				)
			);
		}
	}

	/**
	 * Inline CSS and the filter script, attached to the core admin bar assets.
	 */
	public function assets() {
		if ( ! is_admin_bar_showing() || ! current_user_can( $this->capability() ) ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-psf > .ab-sub-wrapper{max-height:calc(100vh - 48px);overflow-y:auto;min-width:340px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-section > .ab-item{font-size:11px;text-transform:uppercase;letter-spacing:.04em;opacity:.7;margin-top:6px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-plugin > .ab-item{font-weight:600;margin-top:4px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-plugin{border-top:1px solid rgba(240,246,252,.22);margin-top:6px;padding-top:4px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-sort + .psf-plugin,#wpadminbar #wp-admin-bar-psf .psf-section + .psf-plugin{border-top:0;margin-top:0;padding-top:0}'
			. '#wpadminbar #wp-admin-bar-psf .psf-plugin-empty > .ab-item{opacity:.75}'
			. '#wpadminbar #wp-admin-bar-psf .psf-entry > .ab-item{padding-left:24px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-none > .ab-item{font-style:italic;opacity:.65}'
			. '#wpadminbar #wp-admin-bar-psf .psf-info > .ab-item{font-size:12px;line-height:18px;height:auto;min-height:20px;white-space:normal;max-width:420px;padding-top:2px;padding-bottom:2px;opacity:.9}'
			. '#wpadminbar #wp-admin-bar-psf .psf-pcp{margin:2px 10px 6px 24px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-pcp > .ab-item{padding-left:0;color:#68de7c}'
			. '#wpadminbar #wp-admin-bar-psf .psf-pcp > .ab-item:hover,#wpadminbar #wp-admin-bar-psf .psf-pcp > .ab-item:focus{color:#fff;background:#00a32a}'
			. '#wpadminbar #wp-admin-bar-psf .psf-pcp .dashicons{font:normal 16px/1 dashicons;vertical-align:-3px;margin:0 4px 0 6px;color:inherit}'
			. '#wpadminbar #wp-admin-bar-psf .psf-badge{font-size:10px;line-height:1;padding:2px 5px;border-radius:3px;background:rgba(240,246,252,.15);margin-left:6px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-new{background:#2271b1;color:#fff}'
			. '#wpadminbar #wp-admin-bar-psf .psf-filter > .ab-item{height:auto;padding:6px 10px}'
			. '#wpadminbar #wp-admin-bar-psf .psf-sort > .ab-item{font-size:12px;opacity:.85}'
			. '#wpadminbar #wp-admin-bar-psf .psf-sort a.psf-sort-link{display:inline;padding:0;height:auto;color:#72aee6;text-decoration:underline}'
			. '#wpadminbar #wp-admin-bar-psf .psf-sort .psf-sort-on{font-weight:600}'
			. '#wpadminbar #wp-admin-bar-psf .psf-filter-input{width:100%;box-sizing:border-box;height:26px;font-size:13px;padding:2px 6px;border-radius:3px;border:1px solid #8c8f94;background:#fff;color:#1d2327}'
		);

		wp_add_inline_script(
			'admin-bar',
			'(function(){document.addEventListener("input",function(e){'
			. 'if(!e.target.classList||!e.target.classList.contains("psf-filter-input"))return;'
			. 'var q=e.target.value.toLowerCase().trim(),groups=[],cur=null;'
			. 'document.querySelectorAll("#wp-admin-bar-psf-default > li").forEach(function(li){'
			. 'if(li.classList.contains("psf-plugin")){cur={items:[li],text:li.textContent+" "+(li.title||"")};groups.push(cur);}'
			. 'else if(li.classList.contains("psf-entry")&&cur){cur.items.push(li);cur.text+=" "+li.textContent;}'
			. 'else if(li.classList.contains("psf-section")){li.style.display=q?"none":"";}});'
			. 'groups.forEach(function(g){var show=!q||g.text.toLowerCase().indexOf(q)>-1;g.items.forEach(function(li){li.style.display=show?"":"none";});});'
			. '});})();'
		);
	}
}
