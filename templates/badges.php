<?php
/**
 * Template: badges gallery with progress toward locked ones.
 *
 * Available: $rewards (Zeko_Rewards), $db (Zeko_Rewards_DB),
 * $engine (Zeko_Rewards_Engine), $user_id (int).
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$badges   = $db->get_badges( true );
$lifetime = $user_id > 0 ? $db->user_lifetime_points( $user_id ) : 0;
$unlocked = $user_id > 0 ? $db->get_user_badges( $user_id ) : array();
$unlocked = array_column( $unlocked, 'badge_id' );
$unlocked = array_map( 'absint', $unlocked );
?>
<div class="zr-badges">
	<nav class="zr-tabs" aria-label="<?php esc_attr_e( 'Rewards pages', 'zeko-rewards' ); ?>">
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards' ) ); ?>"><?php esc_html_e( 'Dashboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-leaderboard' ) ); ?>"><?php esc_html_e( 'Leaderboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab zr-tab-active" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-badges' ) ); ?>"><?php esc_html_e( 'Badges', 'zeko-rewards' ); ?></a>
	</nav>
	<?php if ( $badges ) : ?>
		<ul class="zr-badge-grid">
			<?php foreach ( $badges as $badge ) : ?>
				<?php
				$is_unlocked = in_array( (int) $badge['badge_id'], $unlocked, true );
				$progress    = 0;
				switch ( $badge['criteria_type'] ) {
					case 'points_total':
						$progress    = $lifetime;
						$denominator = (int) $badge['criteria_value'];
						break;
					case 'module_points':
						$progress    = $user_id > 0 ? $db->module_points( $user_id, (string) $badge['criteria_module'] ) : 0;
						$denominator = (int) $badge['criteria_value'];
						break;
					default:
						$progress    = $user_id > 0 ? $db->action_count( $user_id, (string) $badge['criteria_action'] ) : 0;
						$denominator = (int) $badge['criteria_value'];
						break;
				}
				$pct = (int) min( 100, $denominator > 0 ? round( ( $progress / $denominator ) * 100 ) : 0 );
				?>
				<li class="zr-badge<?php echo $is_unlocked ? ' zr-unlocked' : ''; ?>">
					<span class="zr-badge-icon zr-module-<?php echo esc_attr( $badge['module'] ); ?>">
						<?php echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( $badge['name'], 0, 1 ) : substr( $badge['name'], 0, 1 ) ); ?>
					</span>
					<span class="zr-badge-name"><?php echo esc_html( $badge['name'] ); ?></span>
					<span class="zr-badge-desc"><?php echo esc_html( $badge['description'] ); ?></span>
					<?php if ( (int) $badge['points'] > 0 ) : ?>
						<span class="zr-badge-bonus"><?php /* translators: %d: number of points */ echo esc_html( sprintf( __( '+%d bonus points', 'zeko-rewards' ), (int) $badge['points'] ) ); ?></span>
					<?php endif; ?>
					<?php if ( $is_unlocked ) : ?>
						<span class="zr-badge-status"><?php esc_html_e( 'Unlocked', 'zeko-rewards' ); ?></span>
					<?php elseif ( $user_id > 0 ) : ?>
						<div class="zr-progress">
							<div class="zr-progress-bar" style="width: <?php echo esc_attr( $pct ); ?>%"></div>
						</div>
						<span class="zr-badge-status">
							<?php /* translators: 1: progress value. 2: required value */ echo esc_html( sprintf( __( '%1$d / %2$d', 'zeko-rewards' ), $progress, $denominator ) ); ?>
						</span>
					<?php else : ?>
						<span class="zr-badge-status zr-locked"><?php esc_html_e( 'Locked', 'zeko-rewards' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="zr-empty"><?php esc_html_e( 'No badges configured yet.', 'zeko-rewards' ); ?></p>
	<?php endif; ?>
</div>
