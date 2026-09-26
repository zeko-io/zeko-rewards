<?php
/**
 * Zeko Rewards admin page: redemption settings, per-action point values,
 * badge catalog overview.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards_Admin. */
class Zeko_Rewards_Admin {

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

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_dependency_notice' ) );

		add_action( 'admin_post_zeko_rewards_catalog_save', array( $this, 'handle_catalog_save' ) );
		add_action( 'admin_post_zeko_rewards_catalog_delete', array( $this, 'handle_catalog_delete' ) );
		add_action( 'admin_post_zeko_rewards_redemption_action', array( $this, 'handle_redemption_action' ) );
		add_action( 'admin_post_zeko_rewards_manual_adjust', array( $this, 'handle_manual_adjust' ) );
		add_action( 'admin_post_zeko_rewards_export_leaderboard', array( $this, 'export_leaderboard_csv' ) );
	}

	/**
	 * Menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Zeko Rewards', 'zeko-rewards' ),
			__( 'Rewards', 'zeko-rewards' ),
			'manage_options',
			'zeko-rewards',
			array( $this, 'render' ),
			'dashicons-awards',
			57
		);

		add_submenu_page(
			'zeko-rewards',
			__( 'Reward Catalog', 'zeko-rewards' ),
			__( 'Catalog', 'zeko-rewards' ),
			'manage_options',
			'zeko-rewards-catalog',
			array( $this, 'render_catalog' )
		);

		add_submenu_page(
			'zeko-rewards',
			__( 'Redemption Requests', 'zeko-rewards' ),
			__( 'Redemptions', 'zeko-rewards' ),
			'manage_options',
			'zeko-rewards-redemptions',
			array( $this, 'render_redemptions' )
		);

		add_submenu_page(
			'zeko-rewards',
			__( 'Adjust Points', 'zeko-rewards' ),
			__( 'Adjust Points', 'zeko-rewards' ),
			'manage_options',
			'zeko-rewards-adjust',
			array( $this, 'render_adjust' )
		);
	}

	/**
	 * Show an admin notice when point redemption needs Zeko Pay's wallet but
	 * Zeko Pay is not active. The rest of Rewards works without it.
	 */
	public function maybe_show_dependency_notice(): void {
		$settings  = zeko_rewards_get_settings();
		$needs_pay = empty( $settings['enable_redemption'] ) ? false : true;
		if ( ! $needs_pay || class_exists( 'Zeko_Pay' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Zeko Rewards: point redemption to a wallet requires the Zeko Pay plugin to be installed and active.', 'zeko-rewards' );
		echo '</p></div>';
	}

	/**
	 * Settings.
	 */
	public function register_settings(): void {
		register_setting( 'zeko_rewards_settings_group', 'zeko_rewards_settings', array( $this, 'sanitize_settings' ) );
		register_setting( 'zeko_rewards_settings_group', 'zeko_rewards_config_override', array( $this, 'sanitize_override' ) );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Input.
	 */
	public function sanitize_settings( $input ): array {
		$defaults = zeko_rewards_get_settings();
		$input    = is_array( $input ) ? $input : array();

		return array(
			'enable_redemption'      => empty( $input['enable_redemption'] ) ? 0 : 1,
			'points_per_dollar'      => isset( $input['points_per_dollar'] ) ? max( 1, absint( $input['points_per_dollar'] ) ) : $defaults['points_per_dollar'],
			'min_redeem_points'      => isset( $input['min_redeem_points'] ) ? max( 1, absint( $input['min_redeem_points'] ) ) : $defaults['min_redeem_points'],
			'expire_days'            => isset( $input['expire_days'] ) ? max( 0, absint( $input['expire_days'] ) ) : $defaults['expire_days'],
			'streak_enabled'         => empty( $input['streak_enabled'] ) ? 0 : 1,
			'streak_bonus_every'     => isset( $input['streak_bonus_every'] ) ? max( 1, absint( $input['streak_bonus_every'] ) ) : $defaults['streak_bonus_every'],
			'streak_bonus'           => isset( $input['streak_bonus'] ) ? max( 0, absint( $input['streak_bonus'] ) ) : $defaults['streak_bonus'],
			'streak_multiplier'      => isset( $input['streak_multiplier'] ) ? max( 0, absint( $input['streak_multiplier'] ) ) : $defaults['streak_multiplier'],
			'streak_multiplier_days' => isset( $input['streak_multiplier_days'] ) ? max( 1, absint( $input['streak_multiplier_days'] ) ) : $defaults['streak_multiplier_days'],
		);
	}

	/**
	 * Sanitize override.
	 *
	 * @param mixed $input Input.
	 */
	public function sanitize_override( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$config = zeko_rewards_get_config();
		$clean  = array();
		foreach ( $input as $action => $points ) {
			$action = sanitize_key( (string) $action );
			if ( ! isset( $config[ $action ] ) || ! empty( $config[ $action ]['internal'] ) ) {
				continue;
			}
			$clean[ $action ] = max( 0, absint( $points ) );
		}
		return $clean;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = zeko_rewards_get_settings();
		$config   = zeko_rewards_get_config();
		$override = get_option( 'zeko_rewards_config_override', array() );
		$badges   = $this->db->get_badges( false );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zeko Rewards', 'zeko-rewards' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'zeko_rewards_settings_group' ); ?>

				<h2><?php esc_html_e( 'Redemption & expiration', 'zeko-rewards' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zr-enable-redemption"><?php esc_html_e( 'Enable redemption', 'zeko-rewards' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="zeko_rewards_settings[enable_redemption]" value="1"
									id="zr-enable-redemption" <?php checked( 1, (int) $settings['enable_redemption'] ); ?> />
								<?php esc_html_e( 'Allow users to exchange points for Zeko Pay wallet credit.', 'zeko-rewards' ); ?>
							</label>
							<?php if ( ! Zeko_Rewards_Pay::active() ) : ?>
								<p class="description" style="color:#b32d2e;">
									<?php esc_html_e( 'The Zeko Pay plugin is not active — redemptions will fail until it is enabled.', 'zeko-rewards' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-points-per-dollar"><?php esc_html_e( 'Points per $1', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="zeko_rewards_settings[points_per_dollar]" id="zr-points-per-dollar"
								min="1" step="1" value="<?php echo esc_attr( (int) $settings['points_per_dollar'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How many points equal one dollar of wallet credit.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-min-redeem"><?php esc_html_e( 'Minimum redemption', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="zeko_rewards_settings[min_redeem_points]" id="zr-min-redeem"
								min="1" step="1" value="<?php echo esc_attr( (int) $settings['min_redeem_points'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Minimum points a user may redeem in one transaction.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-expire-days"><?php esc_html_e( 'Points expire after (days)', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="zeko_rewards_settings[expire_days]" id="zr-expire-days"
								min="0" step="1" value="<?php echo esc_attr( (int) $settings['expire_days'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Points older than this many days stop counting toward balance and tier. Set 0 to never expire.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Streaks & multipliers', 'zeko-rewards' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zr-streak-enabled"><?php esc_html_e( 'Enable streaks', 'zeko-rewards' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="zeko_rewards_settings[streak_enabled]" value="1"
									id="zr-streak-enabled" <?php checked( 1, (int) $settings['streak_enabled'] ); ?> />
								<?php esc_html_e( 'Track consecutive days of earning activity (reset when a day is missed).', 'zeko-rewards' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-streak-every"><?php esc_html_e( 'Bonus every (days)', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="zeko_rewards_settings[streak_bonus_every]" id="zr-streak-every"
								min="1" step="1" value="<?php echo esc_attr( (int) $settings['streak_bonus_every'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'A bonus is paid each time the streak reaches this multiple.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-streak-bonus"><?php esc_html_e( 'Streak bonus points', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="zeko_rewards_settings[streak_bonus]" id="zr-streak-bonus"
								min="0" step="1" value="<?php echo esc_attr( (int) $settings['streak_bonus'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Points awarded at each streak milestone. Set 0 to disable the bonus.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-streak-multiplier"><?php esc_html_e( 'Streak multiplier', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="zeko_rewards_settings[streak_multiplier]" id="zr-streak-multiplier"
								min="0" step="1" value="<?php echo esc_attr( (int) $settings['streak_multiplier'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Multiply all earned points (e.g. 2 = double) while the streak is active. Set 0 or 1 to disable.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-streak-multiplier-days"><?php esc_html_e( 'Multiplier needs (days)', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="zeko_rewards_settings[streak_multiplier_days]" id="zr-streak-multiplier-days"
								min="1" step="1" value="<?php echo esc_attr( (int) $settings['streak_multiplier_days'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Minimum streak length before the multiplier applies.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Points per activity', 'zeko-rewards' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Adjust how many points each activity awards. "Once" actions can only be earned once per user.', 'zeko-rewards' ); ?>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Action', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Module', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Label', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Points', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Limit', 'zeko-rewards' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $config as $action => $cfg ) : ?>
							<?php
							if ( ! empty( $cfg['internal'] ) ) {
								continue;}
							?>
							<tr>
								<td><code><?php echo esc_html( $action ); ?></code></td>
								<td><?php echo esc_html( $cfg['module'] ); ?></td>
								<td><?php echo esc_html( $cfg['label'] ); ?></td>
								<td>
									<input type="number" name="zeko_rewards_config_override[<?php echo esc_attr( $action ); ?>]"
										min="0" step="1" value="<?php echo esc_attr( isset( $override[ $action ] ) ? (int) $override[ $action ] : (int) $cfg['points'] ); ?>" class="small-text" />
								</td>
								<td><?php echo empty( $cfg['once'] ) ? esc_html__( 'Repeats', 'zeko-rewards' ) : esc_html__( 'Once', 'zeko-rewards' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Badges', 'zeko-rewards' ); ?></h2>
					<?php if ( $badges ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Badge', 'zeko-rewards' ); ?></th>
								<th><?php esc_html_e( 'Criteria', 'zeko-rewards' ); ?></th>
								<th><?php esc_html_e( 'Bonus points', 'zeko-rewards' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $badges as $badge ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $badge['name'] ); ?></strong>
										<p class="description"><?php echo esc_html( $badge['description'] ); ?></p>
									</td>
									<td>
										<code>
											<?php
											echo esc_html(
												'points_total' === $badge['criteria_type']
													/* translators: %d: number of lifetime points */
													? sprintf( __( '%d lifetime points', 'zeko-rewards' ), (int) $badge['criteria_value'] )
													/* translators: 1: criteria description. 2: criteria value */
													: sprintf( __( '%1$s: %2$d', 'zeko-rewards' ), $badge['criteria_type'] . ' ' . ( $badge['criteria_action'] ? $badge['criteria_action'] : $badge['criteria_module'] ), (int) $badge['criteria_value'] )
											);
											?>
										</code>
									</td>
									<td><?php echo esc_html( number_format_i18n( (int) $badge['points'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No badges seeded yet.', 'zeko-rewards' ); ?></p>
				<?php endif; ?>

					<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Leaderboard export', 'zeko-rewards' ); ?></h2>
			<p class="description">
					<?php esc_html_e( 'Download the top 1,000 users by lifetime points as a CSV file.', 'zeko-rewards' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zeko_rewards_export_leaderboard" />
					<?php wp_nonce_field( 'zeko_rewards_export_leaderboard' ); ?>
					<?php submit_button( __( 'Export leaderboard CSV', 'zeko-rewards' ), 'secondary', '', false ); ?>
			</form>
		</div>
			<?php
	}

	/**
	 * Render catalog.
	 */
	public function render_catalog(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zeko-rewards' ) );
		}

		$edit_item = null;
		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag selecting which catalog item to prefill in the admin edit form; no state change.
			$edit_item = $this->db->get_catalog_item( absint( wp_unslash( $_GET['edit'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; value int-cast before DB query.
		}
		$items = $this->db->get_catalog( false );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reward Catalog', 'zeko-rewards' ); ?></h1>

			<?php $this->notice(); ?>

			<h2><?php echo $edit_item ? esc_html__( 'Edit reward', 'zeko-rewards' ) : esc_html__( 'Add reward', 'zeko-rewards' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zeko_rewards_catalog_save" />
				<?php wp_nonce_field( 'zeko_rewards_catalog_save', 'zeko_rewards_nonce' ); ?>
				<?php if ( $edit_item ) : ?>
					<input type="hidden" name="item_id" value="<?php echo esc_attr( (int) $edit_item['id'] ); ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zr-item-name"><?php esc_html_e( 'Name', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="text" name="name" id="zr-item-name" class="regular-text" required
								value="<?php echo $edit_item ? esc_attr( $edit_item['name'] ) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-item-desc"><?php esc_html_e( 'Description', 'zeko-rewards' ); ?></label></th>
						<td>
							<textarea name="description" id="zr-item-desc" class="large-text" rows="3"><?php echo $edit_item ? esc_textarea( $edit_item['description'] ) : ''; ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-item-cost"><?php esc_html_e( 'Points cost', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="points_cost" id="zr-item-cost" min="1" step="1" required
								value="<?php echo $edit_item ? esc_attr( (int) $edit_item['points_cost'] ) : ''; ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-item-stock"><?php esc_html_e( 'Stock', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="stock" id="zr-item-stock" min="-1" step="1"
								value="<?php echo $edit_item ? esc_attr( (int) $edit_item['stock'] ) : '-1'; ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Number available. Use -1 for unlimited.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-item-image"><?php esc_html_e( 'Image URL', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="url" name="image_url" id="zr-item-image" class="regular-text"
								value="<?php echo $edit_item ? esc_attr( $edit_item['image_url'] ) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Active', 'zeko-rewards' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="is_active" value="1"
									<?php checked( 1, $edit_item ? (int) $edit_item['is_active'] : 1 ); ?> />
								<?php esc_html_e( 'Visible and redeemable by users.', 'zeko-rewards' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( $edit_item ? __( 'Update reward', 'zeko-rewards' ) : __( 'Add reward', 'zeko-rewards' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Current catalog', 'zeko-rewards' ); ?></h2>
			<?php if ( $items ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Status', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'zeko-rewards' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item['name'] ); ?></strong>
									<p class="description"><?php echo esc_html( $item['description'] ); ?></p>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $item['points_cost'] ) ); ?></td>
								<td>
									<?php
									echo (int) $item['stock'] < 0
										? esc_html__( 'Unlimited', 'zeko-rewards' )
										: esc_html( number_format_i18n( (int) $item['stock'] ) );
									?>
								</td>
								<td>
									<?php if ( (int) $item['is_active'] ) : ?>
										<span style="color:#00a32a;"><?php esc_html_e( 'Active', 'zeko-rewards' ); ?></span>
									<?php else : ?>
										<span style="color:#b32d2e;"><?php esc_html_e( 'Inactive', 'zeko-rewards' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=zeko-rewards-catalog&edit=' . (int) $item['id'] ) ); ?>">
										<?php esc_html_e( 'Edit', 'zeko-rewards' ); ?>
									</a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="zeko_rewards_catalog_delete" />
										<input type="hidden" name="item_id" value="<?php echo esc_attr( (int) $item['id'] ); ?>" />
										<?php wp_nonce_field( 'zeko_rewards_catalog_delete', 'zeko_rewards_nonce' ); ?>
										<?php submit_button( __( 'Delete', 'zeko-rewards' ), 'button-link-delete', '', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No rewards yet. Add your first reward above.', 'zeko-rewards' ); ?></p>
			<?php endif; ?>
		</div>
			<?php
	}

	/**
	 * Render redemptions.
	 */
	public function render_redemptions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zeko-rewards' ) );
		}

		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter for the redemptions table; allow-listed against $allowed below, capability checked above.
		$allowed = array( '', 'pending', 'completed', 'cancelled' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'pending';
		}
		$rows = $this->db->get_catalog_redemptions( 0, 100, $status );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redemption Requests', 'zeko-rewards' ); ?></h1>

			<?php $this->notice(); ?>

			<nav class="nav-tab-wrapper">
				<?php
				foreach ( array(
					'pending'   => __( 'Pending', 'zeko-rewards' ),
					'completed' => __( 'Completed', 'zeko-rewards' ),
					'cancelled' => __( 'Cancelled', 'zeko-rewards' ),
					''          => __( 'All', 'zeko-rewards' ),
				) as $key => $label ) :
					?>
					<a class="nav-tab <?php echo $status === $key ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=zeko-rewards-redemptions&status=' . $key ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

				<?php if ( $rows ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Reward', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Points', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Requested', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Status', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'zeko-rewards' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['display_name'] ? $row['display_name'] : '#' . (int) $row['user_id'] ); ?></td>
								<td><?php echo esc_html( $row['item_name'] ? $row['item_name'] : '#' . (int) $row['item_id'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['points'] ) ); ?></td>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
								<td><?php echo esc_html( ucfirst( $row['status'] ) ); ?></td>
								<td>
									<?php if ( 'pending' === $row['status'] ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<input type="hidden" name="action" value="zeko_rewards_redemption_action" />
											<input type="hidden" name="redemption_id" value="<?php echo esc_attr( (int) $row['id'] ); ?>" />
											<input type="hidden" name="redemption_status" value="completed" />
											<input type="hidden" name="admin_note" value="" />
											<?php wp_nonce_field( 'zeko_rewards_redemption_action', 'zeko_rewards_nonce' ); ?>
											<?php submit_button( __( 'Complete', 'zeko-rewards' ), 'button button-small', '', false ); ?>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<input type="hidden" name="action" value="zeko_rewards_redemption_action" />
											<input type="hidden" name="redemption_id" value="<?php echo esc_attr( (int) $row['id'] ); ?>" />
											<input type="hidden" name="redemption_status" value="cancelled" />
											<input type="hidden" name="admin_note" value="" />
											<?php wp_nonce_field( 'zeko_rewards_redemption_action', 'zeko_rewards_nonce' ); ?>
											<?php submit_button( __( 'Cancel & refund', 'zeko-rewards' ), 'button-link-delete button-small', '', false ); ?>
										</form>
									<?php elseif ( $row['admin_note'] ) : ?>
										<p class="description"><?php echo esc_html( $row['admin_note'] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No redemption requests found.', 'zeko-rewards' ); ?></p>
			<?php endif; ?>
		</div>
			<?php
	}

	/**
	 * Render adjust.
	 */
	public function render_adjust(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zeko-rewards' ) );
		}

		$recent = $this->db->recent_manual_adjustments( 25 );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Adjust Points', 'zeko-rewards' ); ?></h1>

			<?php $this->notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zeko_rewards_manual_adjust" />
				<?php wp_nonce_field( 'zeko_rewards_manual_adjust', 'zeko_rewards_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zr-adjust-user"><?php esc_html_e( 'User', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="text" name="user" id="zr-adjust-user" class="regular-text" required
								placeholder="<?php esc_attr_e( 'User ID, email, username, or display name', 'zeko-rewards' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-adjust-points"><?php esc_html_e( 'Points', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="number" name="points" id="zr-adjust-points" step="1" required class="small-text" />
							<p class="description"><?php esc_html_e( 'Positive to award, negative to deduct.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zr-adjust-note"><?php esc_html_e( 'Reason', 'zeko-rewards' ); ?></label></th>
						<td>
							<input type="text" name="note" id="zr-adjust-note" class="regular-text" required
								placeholder="<?php esc_attr_e( 'e.g. Compensation for event issue', 'zeko-rewards' ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown to the user and recorded in the audit ledger.', 'zeko-rewards' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Apply adjustment', 'zeko-rewards' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent adjustments', 'zeko-rewards' ); ?></h2>
			<?php if ( $recent ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Points', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'zeko-rewards' ); ?></th>
							<th><?php esc_html_e( 'Date', 'zeko-rewards' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $event ) : ?>
							<tr>
								<td><?php echo esc_html( $event['display_name'] ? $event['display_name'] : '#' . (int) $event['user_id'] ); ?></td>
								<td style="color:<?php echo (int) $event['points'] < 0 ? '#b32d2e' : '#00a32a'; ?>;">
									<?php echo esc_html( ( (int) $event['points'] > 0 ? '+' : '' ) . number_format_i18n( (int) $event['points'] ) ); ?>
								</td>
								<td><?php echo esc_html( $event['description'] ); ?></td>
								<td><?php echo esc_html( $event['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No manual adjustments recorded yet.', 'zeko-rewards' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle catalog save.
	 */
	public function handle_catalog_save(): void {
		$this->guard( 'zeko_rewards_catalog_save' );

		$item_id = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		$data    = array(
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
			'points_cost' => max( 1, absint( wp_unslash( $_POST['points_cost'] ?? 0 ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
			'stock'       => isset( $_POST['stock'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['stock'] ) ) : -1, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
			'image_url'   => esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
			'is_active'   => empty( $_POST['is_active'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		);

		if ( $item_id ) {
			$this->db->update_catalog_item( $item_id, $data );
			$this->redirect( admin_url( 'admin.php?page=zeko-rewards-catalog&message=updated' ) );
		}

		$name = trim( $data['name'] );
		if ( '' === $name ) {
			$this->redirect( admin_url( 'admin.php?page=zeko-rewards-catalog&message=invalid' ) );
		}

		$this->db->insert_catalog_item( $data );
		$this->redirect( admin_url( 'admin.php?page=zeko-rewards-catalog&message=added' ) );
	}

	/**
	 * Handle catalog delete.
	 */
	public function handle_catalog_delete(): void {
		$this->guard( 'zeko_rewards_catalog_delete' );

		$item_id = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		if ( $item_id ) {
			$this->db->delete_catalog_item( $item_id );
		}

		$this->redirect( admin_url( 'admin.php?page=zeko-rewards-catalog&message=deleted' ) );
	}

	/**
	 * Handle redemption action.
	 */
	public function handle_redemption_action(): void {
		$this->guard( 'zeko_rewards_redemption_action' );

		$redemption_id = isset( $_POST['redemption_id'] ) ? absint( wp_unslash( $_POST['redemption_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		$status        = isset( $_POST['redemption_status'] ) ? sanitize_key( wp_unslash( $_POST['redemption_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		$note          = sanitize_text_field( wp_unslash( $_POST['admin_note'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.

		if ( $redemption_id && 'completed' === $status ) {
			$this->db->update_catalog_redemption_status( $redemption_id, 'completed', $note );
			$this->redirect( admin_url( 'admin.php?page=zeko-rewards-redemptions&status=pending&message=completed' ) );
		}

		if ( $redemption_id && 'cancelled' === $status ) {
			$result = zeko_rewards()->get_engine()->cancel_catalog_redemption( $redemption_id, $note );
			$this->redirect(
				admin_url( 'admin.php?page=zeko-rewards-redemptions&status=pending&message=' . ( empty( $result['success'] ) ? 'cancel_failed' : 'cancelled' ) )
			);
		}

		$this->redirect( admin_url( 'admin.php?page=zeko-rewards-redemptions&status=pending&message=invalid' ) );
	}

	/**
	 * Handle manual adjust.
	 */
	public function handle_manual_adjust(): void {
		$this->guard( 'zeko_rewards_manual_adjust' );

		$user = sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		$user = $this->resolve_user( $user );

		if ( ! $user || ! isset( $_POST['points'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
			$this->redirect( admin_url( 'admin.php?page=zeko-rewards-adjust&message=user_not_found' ) );
		}

		$points = absint( wp_unslash( $_POST['points'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		if ( 0 === $points ) {
			$this->redirect( admin_url( 'admin.php?page=zeko-rewards-adjust&message=invalid' ) );
		}

		$note     = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		$event_id = zeko_rewards()->get_engine()->adjust_points( $user->ID, $points, $note, get_current_user_id() );

		$this->redirect(
			admin_url(
				'admin.php?page=zeko-rewards-adjust&message=' . ( $event_id ? 'adjusted' : 'failed' )
			)
		);
	}

	/**
	 * Export leaderboard csv.
	 */
	public function export_leaderboard_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zeko-rewards' ) );
		}
		check_admin_referer( 'zeko_rewards_export_leaderboard' );

		$rows = $this->db->get_leaderboard( 1000 );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zeko-rewards-leaderboard-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Rank', 'User', 'Lifetime Points', 'Badges' ) );
		foreach ( $rows as $index => $row ) {
			fputcsv(
				$out,
				array(
					$index + 1,
					$row['display_name'],
					(int) $row['lifetime_points'],
					(int) $row['badge_count'],
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Guard.
	 *
	 * @param string $action Action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zeko-rewards' ) );
		}
		check_admin_referer( $action, 'zeko_rewards_nonce' );
	}

	/**
	 * Resolve user.
	 *
	 * @param string $input Input.
	 */
	private function resolve_user( string $input ): ?WP_User {
		$user = null;

		if ( is_numeric( $input ) ) {
			$user = get_user_by( 'id', (int) $input );
		}
		if ( ! $user && strpos( $input, '@' ) !== false ) {
			$user = get_user_by( 'email', $input );
		}
		if ( ! $user ) {
			$user = get_user_by( 'login', $input );
		}
		if ( ! $user ) {
			$user = get_user_by( 'slug', $input );
		}

		return $user ? $user : null;
	}

	/**
	 * Redirect.
	 *
	 * @param string $url Url.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Notice.
	 */
	private function notice(): void {
		if ( ! isset( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status flag; value mapped through $map below which is a fixed allow-list, output escaped.
			return;
		}
		$map = array(
			'added'          => array( __( 'Reward added.', 'zeko-rewards' ), 'success' ),
			'updated'        => array( __( 'Reward updated.', 'zeko-rewards' ), 'success' ),
			'deleted'        => array( __( 'Reward deleted.', 'zeko-rewards' ), 'success' ),
			'completed'      => array( __( 'Request marked complete.', 'zeko-rewards' ), 'success' ),
			'cancelled'      => array( __( 'Request cancelled and points refunded.', 'zeko-rewards' ), 'success' ),
			'cancel_failed'  => array( __( 'Could not cancel request.', 'zeko-rewards' ), 'error' ),
			'adjusted'       => array( __( 'Points adjusted.', 'zeko-rewards' ), 'success' ),
			'user_not_found' => array( __( 'User not found.', 'zeko-rewards' ), 'error' ),
			'failed'         => array( __( 'Adjustment failed.', 'zeko-rewards' ), 'error' ),
			'invalid'        => array( __( 'Invalid request.', 'zeko-rewards' ), 'error' ),
		);
		$key = sanitize_key( wp_unslash( $_GET['message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status flag; allow-listed against $map, output escaped.
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		list( $text, $type ) = $map[ $key ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}
}
