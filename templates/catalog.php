<?php
/**
 * Template: reward catalog with request buttons + user redemption history.
 *
 * Available: $rewards (Zeko_Rewards), $db (Zeko_Rewards_DB),
 * $engine (Zeko_Rewards_Engine), $user_id (int).
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$items    = $db->get_catalog( true );
$balance  = $user_id > 0 ? $db->user_points( $user_id ) : 0;
$requests = $user_id > 0 ? $db->get_catalog_redemptions( $user_id, 20 ) : array();
?>
<div class="zr-catalog">
	<nav class="zr-tabs" aria-label="<?php esc_attr_e( 'Rewards pages', 'zeko-rewards' ); ?>">
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards' ) ); ?>"><?php esc_html_e( 'Dashboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab zr-tab-active" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-catalog' ) ); ?>"><?php esc_html_e( 'Catalog', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-leaderboard' ) ); ?>"><?php esc_html_e( 'Leaderboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-badges' ) ); ?>"><?php esc_html_e( 'Badges', 'zeko-rewards' ); ?></a>
	</nav>

	<?php if ( $user_id > 0 ) : ?>
		<div class="zr-summary">
			<div class="zr-card zr-card-balance">
				<span class="zr-card-label"><?php esc_html_e( 'Available points', 'zeko-rewards' ); ?></span>
				<span class="zr-card-value"><?php echo esc_html( number_format_i18n( $balance ) ); ?></span>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $items ) : ?>
		<ul class="zr-catalog-grid">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$available  = ( (int) $item['stock'] < 0 ) || (int) $item['stock'] > 0;
				$can_afford = $user_id > 0 && $balance >= (int) $item['points_cost'];
				?>
				<li class="zr-catalog-item<?php echo $available ? '' : ' zr-out-of-stock'; ?>">
					<?php if ( $item['image_url'] ) : ?>
						<img class="zr-catalog-image" src="<?php echo esc_url( $item['image_url'] ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>" loading="lazy" />
					<?php endif; ?>
					<span class="zr-catalog-name"><?php echo esc_html( $item['name'] ); ?></span>
					<span class="zr-catalog-desc"><?php echo esc_html( $item['description'] ); ?></span>
					<span class="zr-catalog-cost"><?php echo esc_html( number_format_i18n( (int) $item['points_cost'] ) ); ?> <?php esc_html_e( 'pts', 'zeko-rewards' ); ?></span>
					<span class="zr-catalog-stock">
						<?php
						if ( (int) $item['stock'] < 0 ) {
							esc_html_e( 'In stock', 'zeko-rewards' );
						} else {
							/* translators: %d: remaining stock */
							echo esc_html( sprintf( _n( '%d left', '%d left', (int) $item['stock'], 'zeko-rewards' ), (int) $item['stock'] ) );
						}
						?>
					</span>
					<?php if ( $user_id > 0 ) : ?>
						<form class="zr-catalog-form" data-zr-catalog-redeem="<?php echo esc_attr( (int) $item['id'] ); ?>">
							<button type="submit" class="zr-btn" <?php echo $available && $can_afford ? '' : 'disabled'; ?>>
								<?php esc_html_e( 'Request reward', 'zeko-rewards' ); ?>
							</button>
							<p class="zr-message" data-zr-message role="status" aria-live="polite"></p>
						</form>
					<?php else : ?>
						<p class="zr-empty"><?php esc_html_e( 'Log in to request rewards.', 'zeko-rewards' ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="zr-empty"><?php esc_html_e( 'No rewards available yet — check back soon.', 'zeko-rewards' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Your reward requests', 'zeko-rewards' ); ?></h3>
	<?php if ( $requests ) : ?>
		<ul class="zr-events">
			<?php foreach ( $requests as $request ) : ?>
				<li class="zr-event">
					<span class="zr-event-points zr-negative">-<?php echo esc_html( number_format_i18n( (int) $request['points'] ) ); ?></span>
					<span class="zr-event-note">
						<?php echo esc_html( $request['item_name'] ); ?>
						<em class="zr-event-status"><?php echo esc_html( ucfirst( $request['status'] ) ); ?></em>
					</span>
					<span class="zr-event-date"><?php echo esc_html( mysql2date( 'M j, Y', $request['created_at'] ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="zr-empty"><?php esc_html_e( 'No reward requests yet.', 'zeko-rewards' ); ?></p>
	<?php endif; ?>
</div>
