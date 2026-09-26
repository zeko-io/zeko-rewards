<?php
/**
 * Zeko Rewards ecosystem integration.
 *
 * Wires the rewards module into the shared Zeko experience:
 *   - primary-nav "Rewards" menu (versioned reconciliation, self-healing URLs)
 *   - admin bar "Rewards" node + notification bell
 *   - header actions bell badge with unread reward notifications
 *   - unified dashboard tab
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards_Ecosystem. */
class Zeko_Rewards_Ecosystem {

	/**
	 * MENU VERSION.
	 *
	 * @var mixed
	 */
	private const MENU_VERSION = '1.1.0';

	private const PAGE_SLUGS = array( 'rewards', 'rewards-leaderboard', 'rewards-badges', 'rewards-catalog' );

	/**
	 * Db.
	 *
	 * @var Zeko_Rewards_DB Db.
	 */
	private Zeko_Rewards_DB $db;

	/**
	 * Construct.
	 *
	 * @param Zeko_Rewards_DB $db Db.
	 */
	public function __construct( Zeko_Rewards_DB $db ) {
		$this->db = $db;

		add_action( 'init', array( $this, 'register_nav_menus' ) );
		add_filter( 'zeko_nav_items', array( $this, 'register_nav_items' ) );
		add_action( 'wp_before_admin_bar_render', array( $this, 'add_admin_bar_rewards_node' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_notification_bell' ), 999 );
		add_action( 'zeko_header_top_bar', array( $this, 'render_header_rewards_badge' ) );

		// Dashboard tab.
		add_filter( 'zeko_dashboard_tabs', array( $this, 'add_dashboard_tab' ), 10, 1 );
		add_action( 'zeko_dashboard_tab_content_rewards', array( $this, 'render_dashboard_tab' ) );

		// Keep stored "Rewards" menu URLs in sync with the current scheme.
		add_filter( 'wp_nav_menu_objects', array( $this, 'fix_rewards_menu_urls' ) );

		// Notification source registry.
		add_filter( 'zeko_register_notification_sources', array( $this, 'register_notification_source' ) );
	}

	/**
	 * Register Rewards as a notification source for the core bell.
	 *
	 * @param array $sources Sources.
	 */
	public function register_notification_source( array $sources ): array {
		global $wpdb;
		$sources['rewards'] = array(
			'table'       => $wpdb->prefix . 'zeko_rewards_notifications',
			'type_column' => 'type',
			'has_message' => true,
			'icon'        => 'awards',
			'label'       => __( 'Rewards', 'zeko-rewards' ),
		);
		return $sources;
	}

	/**
	 * Rewrite stored "Rewards" menu item URLs at render time so they always
	 * match the current site scheme (self-heals http URLs stored before SSL).
	 *
	 * @return array
	 * @param array $items Menu item objects.
	 */
	public function fix_rewards_menu_urls( $items ): array {
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->url ) ) {
				continue;
			}
			$path = trim( (string) wp_parse_url( (string) $item->url, PHP_URL_PATH ), '/' );
			if ( in_array( $path, self::PAGE_SLUGS, true ) ) {
				$item->url = zeko_rewards_page_url( $path );
			}
		}
		return $items;
	}

	/**
	 * Nav menus.
	 */
	public function register_nav_menus(): void {
		register_nav_menus(
			array(
				'zeko-rewards' => __( 'Zeko Rewards', 'zeko-rewards' ),
			)
		);
	}

	/**
	 * Register Rewards nav items via the core zeko_nav_items registry.
	 *
	 * @param array $locations Locations.
	 */
	public function register_nav_items( array $locations ): array {
		$locations['primary'][] = array(
			'title'    => __( 'Rewards', 'zeko-rewards' ),
			'url'      => zeko_rewards_page_url( 'rewards' ),
			'order'    => 10,
			'children' => array(
				array(
					'title' => __( 'Rewards', 'zeko-rewards' ),
					'url'   => zeko_rewards_page_url( 'rewards' ),
				),
				array(
					'title' => __( 'Catalog', 'zeko-rewards' ),
					'url'   => zeko_rewards_page_url( 'rewards-catalog' ),
				),
				array(
					'title' => __( 'Leaderboard', 'zeko-rewards' ),
					'url'   => zeko_rewards_page_url( 'rewards-leaderboard' ),
				),
				array(
					'title' => __( 'Badges', 'zeko-rewards' ),
					'url'   => zeko_rewards_page_url( 'rewards-badges' ),
				),
			),
		);
		return $locations;
	}

	/**
	 * Append a "Rewards" item with child pages to the primary nav.
	 *
	 * @deprecated Use register_nav_items() via the zeko_nav_items filter.
	 */
	public function maybe_create_nav_menu_items(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$done = get_option( 'zeko_rewards_menu_version', '' );
		if ( self::MENU_VERSION === $done ) {
			return;
		}

		$locations = get_nav_menu_locations();
		if ( empty( $locations['primary'] ) ) {
			return;
		}

		$menu_id = $locations['primary'];
		$items   = wp_get_nav_menu_items( $menu_id );
		if ( ! $items ) {
			return;
		}

		$parent_id = 0;
		$max_order = 0;

		foreach ( $items as $item ) {
			$order = (int) $item->menu_order;
			if ( $order > $max_order ) {
				$max_order = $order;
			}
			if ( 'Rewards' === $item->title ) {
				$parent_id = (int) $item->ID;
			}
		}

		$children = array(
			'Rewards'     => 'rewards',
			'Catalog'     => 'rewards-catalog',
			'Leaderboard' => 'rewards-leaderboard',
			'Badges'      => 'rewards-badges',
		);

		if ( ! $parent_id ) {
			$parent_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'  => __( 'Rewards', 'zeko-rewards' ),
					'menu-item-url'    => zeko_rewards_page_url( 'rewards' ),
					'menu-item-status' => 'publish',
					'menu-item-type'   => 'custom',
					'menu-item-order'  => $max_order + 1,
				)
			);

			foreach ( $children as $title => $slug ) {
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => $title,
						'menu-item-url'       => zeko_rewards_page_url( $slug ),
						'menu-item-status'    => 'publish',
						'menu-item-type'      => 'custom',
						'menu-item-parent-id' => $parent_id,
					)
				);
			}
		} else {
			$existing_titles = array();
			foreach ( $items as $item ) {
				if ( (int) $item->menu_item_parent === $parent_id ) {
					$existing_titles[ $item->title ] = true;
				}
			}

			foreach ( $children as $title => $slug ) {
				if ( isset( $existing_titles[ $title ] ) ) {
					continue;
				}
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => $title,
						'menu-item-url'       => zeko_rewards_page_url( $slug ),
						'menu-item-status'    => 'publish',
						'menu-item-type'      => 'custom',
						'menu-item-parent-id' => $parent_id,
					)
				);
			}
		}

		update_option( 'zeko_rewards_menu_version', self::MENU_VERSION );
	}

	/**
	 * Render a rewards bell button with an unread-count badge in the header.
	 */
	public function render_header_rewards_badge(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$unread = $this->db->unread_notifications_count( get_current_user_id() );
		?>
		<div class="zeko-header-rewards">
			<a href="<?php echo esc_url( zeko_rewards_page_url( 'rewards' ) ); ?>"
				class="zeko-header-rewards-btn"
				title="<?php echo esc_attr__( 'Rewards', 'zeko-rewards' ); ?>">
				<span class="dashicons dashicons-awards"></span>
				<span><?php echo esc_html__( 'Rewards', 'zeko-rewards' ); ?></span>
				<?php if ( $unread > 0 ) : ?>
					<span class="zeko-header-badge">
						<?php echo esc_html( min( 99, $unread ) ); ?>
					</span>
				<?php endif; ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Add admin bar rewards node.
	 */
	public function add_admin_bar_rewards_node(): void {
		global $wp_admin_bar;

		if ( ! is_user_logged_in() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'zeko-rewards',
				'title' => '<span class="ab-icon dashicons dashicons-awards"></span><span class="ab-label">' . esc_html__( 'Rewards', 'zeko-rewards' ) . '</span>',
				'href'  => zeko_rewards_page_url( 'rewards' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'zeko-rewards-dashboard',
				'parent' => 'zeko-rewards',
				'title'  => __( 'Dashboard', 'zeko-rewards' ),
				'href'   => zeko_rewards_page_url( 'rewards' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'zeko-rewards-leaderboard',
				'parent' => 'zeko-rewards',
				'title'  => __( 'Leaderboard', 'zeko-rewards' ),
				'href'   => zeko_rewards_page_url( 'rewards-leaderboard' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'zeko-rewards-catalog',
				'parent' => 'zeko-rewards',
				'title'  => __( 'Catalog', 'zeko-rewards' ),
				'href'   => zeko_rewards_page_url( 'rewards-catalog' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'zeko-rewards-badges',
				'parent' => 'zeko-rewards',
				'title'  => __( 'Badges', 'zeko-rewards' ),
				'href'   => zeko_rewards_page_url( 'rewards-badges' ),
			)
		);
	}

	/**
	 * Add notification bell.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar.
	 */
	public function add_notification_bell( WP_Admin_Bar $admin_bar ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$unread      = $this->db->unread_notifications_count( $user_id );
		$count_class = $unread > 0 ? 'zr-bell-unread' : '';
		$label       = $unread > 0
			? sprintf(
				'<span class="ab-icon dashicons dashicons-awards"></span><span class="zr-bell-count">%d</span>',
				$unread
			)
			: '<span class="ab-icon dashicons dashicons-awards"></span>';

		$admin_bar->add_node(
			array(
				'id'    => 'zeko-rewards-notifications',
				'title' => $label,
				'href'  => zeko_rewards_page_url( 'rewards' ),
				'meta'  => array(
					'class' => 'zr-admin-bell ' . $count_class,
					'title' => sprintf(
						/* translators: %d: number of unread reward notifications */
						_n( '%d unread reward notification', '%d unread reward notifications', $unread, 'zeko-rewards' ),
						$unread
					),
				),
			)
		);
	}

	/**
	 * Add a "Rewards" tab to the Zeko dashboard.
	 *
	 * @return array
	 * @param array $tabs Existing tabs.
	 */
	public function add_dashboard_tab( array $tabs ): array {
		$tabs['rewards'] = __( 'Rewards', 'zeko-rewards' );
		return $tabs;
	}

	/**
	 * Render the "Rewards" dashboard tab content.
	 */
	public function render_dashboard_tab(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$balance  = $this->db->user_points( $user_id );
		$lifetime = $this->db->user_lifetime_points( $user_id );
		$tiers    = zeko_rewards_get_tiers();
		$tier_key = (string) get_user_meta( $user_id, 'zeko_rewards_tier', true );
		$tier_key = $tier_key ?: 'bronze';
		$rank     = $this->db->get_rank( $user_id );
		$badges   = count( $this->db->get_user_badges( $user_id ) );

		echo '<div class="zeko-rewards-tab" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">';
		foreach ( array(
			array( (string) number_format_i18n( $balance ), __( 'Available points', 'zeko-rewards' ) ),
			array( (string) number_format_i18n( $lifetime ), __( 'Lifetime points', 'zeko-rewards' ) ),
			array( $tiers[ $tier_key ]['label'] ?? $tier_key, __( 'Tier', 'zeko-rewards' ) ),
			array( (string) $badges, __( 'Badges', 'zeko-rewards' ) ),
			array( $rank > 0 ? '#' . number_format_i18n( $rank ) : '-', __( 'Rank', 'zeko-rewards' ) ),
		) as $stat ) {
			echo '<div style="background:var(--color-surface,#fff);border:1px solid var(--color-border,#e2e8f0);border-radius:12px;padding:12px 18px;text-align:center;flex:1;min-width:120px;">';
			echo '<div style="font-size:22px;font-weight:700;color:#4f46e5;">' . esc_html( $stat[0] ) . '</div>';
			echo '<div style="font-size:12px;color:var(--color-text-secondary,#64748b);">' . esc_html( $stat[1] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		echo '<p style="margin:0;"><a class="btn" href="' . esc_url( zeko_rewards_page_url( 'rewards' ) ) . '">' . esc_html__( 'Open Rewards dashboard', 'zeko-rewards' ) . '</a> '
			. '<a class="btn btn-secondary" href="' . esc_url( zeko_rewards_page_url( 'rewards-leaderboard' ) ) . '">' . esc_html__( 'Leaderboard', 'zeko-rewards' ) . '</a> '
			. '<a class="btn btn-secondary" href="' . esc_url( zeko_rewards_page_url( 'rewards-catalog' ) ) . '">' . esc_html__( 'Catalog', 'zeko-rewards' ) . '</a></p>';
	}

	/**
	 * Rewards shortcode pages as menu-ready objects.
	 *
	 * @return object[]
	 */
	public function get_rewards_menu_items(): array {
		$items = array();

		foreach ( self::PAGE_SLUGS as $slug ) {
			$page = class_exists( 'Zeko_Core_Helpers' )
				? Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug )
				: get_page_by_path( $slug );
			if ( ! $page ) {
				continue;
			}

			$items[] = (object) array(
				'title' => get_the_title( $page ),
				'url'   => get_permalink( $page ),
				'slug'  => $slug,
			);
		}

		return $items;
	}
}
