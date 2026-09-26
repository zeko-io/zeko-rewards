<?php
/**
 * Template: rewards dashboard.
 *
 * Available: $rewards (Zeko_Rewards), $db (Zeko_Rewards_DB),
 * $engine (Zeko_Rewards_Engine), $user_id (int).
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings      = zeko_rewards_get_settings();
$tiers         = zeko_rewards_get_tiers();
$balance       = $db->user_points( $user_id );
$lifetime      = $db->user_lifetime_points( $user_id );
$tier_key      = (string) get_user_meta( $user_id, 'zeko_rewards_tier', true );
$tier_key      = $tier_key ?: 'bronze';
$tier_label    = $tiers[ $tier_key ]['label'] ?? $tier_key;
$events        = $db->get_events( $user_id, 20 );
$badges        = $db->get_user_badges( $user_id );
$notifications = $db->get_notifications( $user_id, 10 );
$redemptions   = $db->get_redemptions( $user_id, 10 );

// Progress toward the next tier.
$next_tier         = null;
$tier_progress     = 100;
$tier_order        = array_keys( $tiers );
$current_idx       = array_search( $tier_key, $tier_order, true );
$current_threshold = (int) ( $tiers[ $tier_key ]['threshold'] ?? 0 );
$tier_count        = count( $tier_order );

for ( $i = (int) $current_idx + 1; $i < $tier_count; $i++ ) {
	if ( (int) $tiers[ $tier_order[ $i ] ]['threshold'] > $lifetime ) {
		$next_tier     = $tiers[ $tier_order[ $i ] ];
		$needed        = (int) $next_tier['threshold'] - $lifetime;
		$span          = max( 1, (int) $next_tier['threshold'] - $current_threshold );
		$tier_progress = (int) min( 100, round( ( ( $lifetime - $current_threshold ) / $span ) * 100 ) );
		break;
	}
}

$wallet_active  = Zeko_Rewards_Pay::active();
$wallet_balance = $wallet_active ? Zeko_Rewards_Pay::balance( $user_id ) : '';
?>
<div class="zr-dashboard">
	<nav class="zr-tabs" aria-label="<?php esc_attr_e( 'Rewards pages', 'zeko-rewards' ); ?>">
		<a class="zr-tab zr-tab-active" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards' ) ); ?>"><?php esc_html_e( 'Dashboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-leaderboard' ) ); ?>"><?php esc_html_e( 'Leaderboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-badges' ) ); ?>"><?php esc_html_e( 'Badges', 'zeko-rewards' ); ?></a>
	</nav>
	<div class="zr-summary">
		<div class="zr-card zr-card-balance">
			<span class="zr-card-label"><?php esc_html_e( 'Available points', 'zeko-rewards' ); ?></span>
			<span class="zr-card-value"><?php echo esc_html( number_format_i18n( $balance ) ); ?></span>
		</div>
		<div class="zr-card zr-card-tier">
			<span class="zr-card-label"><?php esc_html_e( 'Current tier', 'zeko-rewards' ); ?></span>
			<span class="zr-card-value zr-tier zr-tier-<?php echo esc_attr( $tier_key ); ?>"><?php echo esc_html( $tier_label ); ?></span>
			<?php if ( $next_tier ) : ?>
				<div class="zr-progress">
					<div class="zr-progress-bar" style="width: <?php echo esc_attr( $tier_progress ); ?>%"></div>
				</div>
				<span class="zr-card-note">
					<?php /* translators: 1: points needed. 2: next tier label */ echo esc_html( sprintf( __( '%1$d points to %2$s', 'zeko-rewards' ), $needed, $next_tier['label'] ) ); ?>
				</span>
			<?php else : ?>
				<span class="zr-card-note"><?php esc_html_e( 'Highest tier reached', 'zeko-rewards' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="zr-card zr-card-badges">
			<span class="zr-card-label"><?php esc_html_e( 'Badges earned', 'zeko-rewards' ); ?></span>
			<span class="zr-card-value"><?php echo esc_html( count( $badges ) ); ?></span>
			<?php if ( $badges ) : ?>
				<span class="zr-card-note"><?php echo esc_html( wp_list_pluck( $badges, 'name' )[0] ?? '' ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $wallet_active && ! empty( $settings['enable_redemption'] ) ) : ?>
		<div class="zr-redeem">
			<h3><?php esc_html_e( 'Redeem points', 'zeko-rewards' ); ?></h3>
			<p class="zr-redeem-note">
				<?php
				echo esc_html(
					sprintf(
						// translators: %1$d points, %2$s currency symbol/amount.
						__( 'Exchange %1$d points for $1 of wallet credit. Wallet balance: %2$s', 'zeko-rewards' ),
						(int) $settings['points_per_dollar'],
						$wallet_balance
					)
				);
				?>
			</p>
			<form class="zr-redeem-form" data-zr-redeem>
				<input
					type="number"
					name="points"
					min="<?php echo esc_attr( (int) $settings['min_redeem_points'] ); ?>"
					step="<?php echo esc_attr( max( 1, (int) $settings['points_per_dollar'] ) ); ?>"
					value="<?php echo esc_attr( max( (int) $settings['min_redeem_points'], (int) $settings['points_per_dollar'] ) ); ?>"
					required
				/>
				<button type="submit" class="zr-btn"><?php esc_html_e( 'Redeem', 'zeko-rewards' ); ?></button>
			</form>
			<p class="zr-message" data-zr-message role="status" aria-live="polite"></p>
		</div>
	<?php endif; ?>

	<?php
	if ( class_exists( 'Zeko_Pay_Referrals' ) ) :
		$ref_code = Zeko_Pay_Referrals::instance()->get_user_code( $user_id );
		if ( $ref_code ) :
			$ref_link = home_url( '/?ref=' . rawurlencode( $ref_code ) );
			?>
		<div class="zr-refer">
			<h3><?php esc_html_e( 'Refer a friend', 'zeko-rewards' ); ?></h3>
			<p class="zr-refer-note">
				<?php esc_html_e( 'Share your code: you earn points when a friend signs up, and they get a bonus too.', 'zeko-rewards' ); ?>
			</p>
			<div class="zr-refer-row">
				<code class="zr-refer-code"><?php echo esc_html( $ref_code ); ?></code>
				<input
					type="text"
					class="zr-refer-link"
					value="<?php echo esc_attr( $ref_link ); ?>"
					readonly
					onfocus="this.select()"
					aria-label="<?php esc_attr_e( 'Referral link', 'zeko-rewards' ); ?>"
				/>
				<button type="button" class="zr-btn" data-zr-copy="<?php echo esc_attr( $ref_link ); ?>">
					<?php esc_html_e( 'Copy link', 'zeko-rewards' ); ?>
				</button>
			</div>
		</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="zr-columns">
		<div class="zr-column">
			<h3><?php esc_html_e( 'Recent activity', 'zeko-rewards' ); ?></h3>
			<?php if ( $events ) : ?>
				<ul class="zr-events">
					<?php foreach ( $events as $event ) : ?>
						<li class="zr-event zr-event-<?php echo esc_attr( $event['source'] ); ?>">
							<span class="zr-event-points <?php echo (int) $event['points'] < 0 ? 'zr-negative' : 'zr-positive'; ?>">
								<?php echo esc_html( ( (int) $event['points'] > 0 ? '+' : '' ) . number_format_i18n( (int) $event['points'] ) ); ?>
							</span>
							<span class="zr-event-note"><?php echo esc_html( $event['note'] ); ?></span>
							<span class="zr-event-date"><?php echo esc_html( mysql2date( 'M j, Y', $event['created_at'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="zr-empty"><?php esc_html_e( 'No activity yet. Explore Zeko to start earning points.', 'zeko-rewards' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="zr-column">
			<h3><?php esc_html_e( 'Notifications', 'zeko-rewards' ); ?></h3>
			<?php if ( $notifications ) : ?>
				<ul class="zr-notifications">
					<?php foreach ( $notifications as $note ) : ?>
						<li class="zr-notification<?php echo $note['is_read'] ? '' : ' zr-unread'; ?>">
							<span class="zr-note-message"><?php echo esc_html( $note['message'] ); ?></span>
							<span class="zr-event-date"><?php echo esc_html( mysql2date( 'M j, Y', $note['created_at'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $db->unread_notifications_count( $user_id ) > 0 ) : ?>
					<button type="button" class="zr-btn zr-btn-sm" data-zr-mark-read><?php esc_html_e( 'Mark all read', 'zeko-rewards' ); ?></button>
				<?php endif; ?>
			<?php else : ?>
				<p class="zr-empty"><?php esc_html_e( 'No notifications.', 'zeko-rewards' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Redemption history', 'zeko-rewards' ); ?></h3>
			<?php if ( $redemptions ) : ?>
				<ul class="zr-events">
					<?php foreach ( $redemptions as $redemption ) : ?>
						<li class="zr-event">
							<span class="zr-event-points zr-negative">-<?php echo esc_html( number_format_i18n( (int) $redemption['points'] ) ); ?></span>
							<span class="zr-event-note">
								<?php /* translators: %s: redeemed amount */ echo esc_html( sprintf( __( 'Redeemed for %s', 'zeko-rewards' ), number_format_i18n( (float) $redemption['amount'], 2 ) ) ); ?>
							</span>
							<span class="zr-event-date"><?php echo esc_html( mysql2date( 'M j, Y', $redemption['created_at'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="zr-empty"><?php esc_html_e( 'No redemptions yet.', 'zeko-rewards' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
