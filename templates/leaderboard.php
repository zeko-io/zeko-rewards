<?php
/**
 * Template: rewards leaderboard.
 *
 * Available: $rewards (Zeko_Rewards), $db (Zeko_Rewards_DB),
 * $engine (Zeko_Rewards_Engine), $user_id (int).
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$module  = isset( $_GET['zr_module'] ) ? sanitize_key( wp_unslash( $_GET['zr_module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only leaderboard filter; sanitized and matched against the $modules allow-list below.
$rows    = $db->get_leaderboard( 25, $module );
$modules = array(
	''       => __( 'All modules', 'zeko-rewards' ),
	'core'   => __( 'Core', 'zeko-rewards' ),
	'love'   => __( 'Love', 'zeko-rewards' ),
	'jobs'   => __( 'Jobs', 'zeko-rewards' ),
	'learn'  => __( 'Learn', 'zeko-rewards' ),
	'mentor' => __( 'Mentor', 'zeko-rewards' ),
	'shop'   => __( 'Shop', 'zeko-rewards' ),
);
$helpers = class_exists( 'Zeko_Core_Helpers' ) ? Zeko_Core_Helpers::get_instance() : null;
$my_rank = $user_id > 0 ? $db->get_rank( $user_id ) : 0;
?>
<div class="zr-leaderboard">
	<nav class="zr-tabs" aria-label="<?php esc_attr_e( 'Rewards pages', 'zeko-rewards' ); ?>">
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards' ) ); ?>"><?php esc_html_e( 'Dashboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab zr-tab-active" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-leaderboard' ) ); ?>"><?php esc_html_e( 'Leaderboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-badges' ) ); ?>"><?php esc_html_e( 'Badges', 'zeko-rewards' ); ?></a>
	</nav>
	<form method="get" class="zr-leaderboard-filter">
		<label for="zr-module-filter"><?php esc_html_e( 'Filter by module', 'zeko-rewards' ); ?></label>
		<select name="zr_module" id="zr-module-filter">
			<?php foreach ( $modules as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $module, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="zr-btn zr-btn-sm"><?php esc_html_e( 'Filter', 'zeko-rewards' ); ?></button>
	</form>

	<?php if ( $my_rank > 0 ) : ?>
		<p class="zr-my-rank">
			<?php /* translators: %d: numeric rank */ echo esc_html( sprintf( __( 'Your rank: #%d', 'zeko-rewards' ), $my_rank ) ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $rows ) : ?>
		<ol class="zr-leaderboard-list">
			<?php foreach ( $rows as $index => $row ) : ?>
				<?php
				$rank = $index + 1;
				/* translators: %d: numeric user ID */
				$name    = $row['display_name'] ? $row['display_name'] : sprintf( __( 'User #%d', 'zeko-rewards' ), (int) $row['user_id'] );
				$is_me   = $user_id > 0 && (int) $row['user_id'] === $user_id;
				$profile = $helpers ? $helpers->get_user_profile_url( (int) $row['user_id'] ) : get_author_posts_url( (int) $row['user_id'] );
				$avatar  = $helpers ? $helpers->get_user_avatar( (int) $row['user_id'] ) : get_avatar_url( (int) $row['user_id'], array( 'size' => 40 ) );
				?>
				<li class="zr-leaderboard-row<?php echo $is_me ? ' zr-is-me' : ''; ?>">
					<span class="zr-rank"><?php echo esc_html( $rank ); ?></span>
					<?php if ( $avatar ) : ?>
						<img class="zr-avatar" src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $name . __( ' profile photo', 'zeko-rewards' ) ); ?>" width="40" height="40" />
					<?php endif; ?>
					<a class="zr-name" href="<?php echo esc_url( $profile ); ?>"><?php echo esc_html( $name ); ?></a>
					<span class="zr-badges"><?php /* translators: %d: number of badges */ echo esc_html( sprintf( _n( '%d badge', '%d badges', (int) $row['badge_count'], 'zeko-rewards' ), (int) $row['badge_count'] ) ); ?></span>
					<span class="zr-points"><?php echo esc_html( number_format_i18n( (int) $row['lifetime_points'] ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php else : ?>
		<p class="zr-empty"><?php esc_html_e( 'No points awarded yet — be the first!', 'zeko-rewards' ); ?></p>
	<?php endif; ?>
</div>
