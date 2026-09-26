<?php
/**
 * Template: points ledger history.
 *
 * Available: $rewards (Zeko_Rewards), $db (Zeko_Rewards_DB),
 * $engine (Zeko_Rewards_Engine), $user_id (int).
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$limit  = 15;
$events = $db->get_events_filtered( $user_id );
$total  = $db->count_events_filtered( $user_id );
$page_list  = max( 1, (int) ceil( $total / $limit ) );

$modules = array(
	''       => __( 'All modules', 'zeko-rewards' ),
	'core'   => __( 'Core', 'zeko-rewards' ),
	'love'   => __( 'Love', 'zeko-rewards' ),
	'jobs'   => __( 'Jobs', 'zeko-rewards' ),
	'learn'  => __( 'Learn', 'zeko-rewards' ),
	'mentor' => __( 'Mentor', 'zeko-rewards' ),
	'shop'   => __( 'Shop', 'zeko-rewards' ),
);

$actions = array(
	''              => __( 'All actions', 'zeko-rewards' ),
	'login'         => __( 'Login', 'zeko-rewards' ),
	'profile'       => __( 'Profile update', 'zeko-rewards' ),
	'manual_adjust' => __( 'Manual adjust', 'zeko-rewards' ),
	'redeem'        => __( 'Redeem', 'zeko-rewards' ),
);
?>
<div class="zr-history">
	<nav class="zr-tabs" aria-label="<?php esc_attr_e( 'Rewards pages', 'zeko-rewards' ); ?>">
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards' ) ); ?>"><?php esc_html_e( 'Dashboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-leaderboard' ) ); ?>"><?php esc_html_e( 'Leaderboard', 'zeko-rewards' ); ?></a>
		<a class="zr-tab" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-badges' ) ); ?>"><?php esc_html_e( 'Badges', 'zeko-rewards' ); ?></a>
		<a class="zr-tab zr-tab-active" href="<?php echo esc_url( zeko_rewards_page_url( 'rewards-history' ) ); ?>"><?php esc_html_e( 'History', 'zeko-rewards' ); ?></a>
	</nav>

	<h2 class="zr-history-title"><?php esc_html_e( 'Points ledger', 'zeko-rewards' ); ?></h2>

	<div class="zr-history-filters" data-zr-history-filters>
		<label for="zr-history-module"><?php esc_html_e( 'Module', 'zeko-rewards' ); ?></label>
		<select id="zr-history-module" data-zr-history-module>
			<?php foreach ( $modules as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<label for="zr-history-action"><?php esc_html_e( 'Action', 'zeko-rewards' ); ?></label>
		<select id="zr-history-action" data-zr-history-action>
			<?php foreach ( $actions as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="button" class="zr-btn zr-btn-sm" data-zr-history-filter-btn><?php esc_html_e( 'Filter', 'zeko-rewards' ); ?></button>
	</div>

	<p class="zr-history-total" data-zr-history-total>
		<?php /* translators: %d: number of events */ echo esc_html( sprintf( _n( '%d event', '%d events', $total, 'zeko-rewards' ), $total ) ); ?>
	</p>

	<div data-zr-history-table>
		<?php if ( $events ) : ?>
			<table class="zr-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'zeko-rewards' ); ?></th>
						<th><?php esc_html_e( 'Action', 'zeko-rewards' ); ?></th>
						<th><?php esc_html_e( 'Module', 'zeko-rewards' ); ?></th>
						<th><?php esc_html_e( 'Points', 'zeko-rewards' ); ?></th>
						<th><?php esc_html_e( 'Note', 'zeko-rewards' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td class="zr-history-date"><?php echo esc_html( mysql2date( 'M j, Y g:i A', $event['created_at'] ) ); ?></td>
							<td><?php echo esc_html( $event['action'] ); ?></td>
							<td>
								<span class="zr-history-module-badge zr-module-<?php echo esc_attr( $event['module'] ); ?>">
									<?php echo esc_html( ucfirst( $event['module'] ) ); ?>
								</span>
							</td>
							<td>
								<span class="zr-history-points <?php echo (int) $event['points'] < 0 ? 'zr-negative' : 'zr-positive'; ?>">
									<?php echo esc_html( ( (int) $event['points'] > 0 ? '+' : '' ) . number_format_i18n( (int) $event['points'] ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $event['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="zr-empty"><?php esc_html_e( 'No events found.', 'zeko-rewards' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( $page_list > 1 ) : ?>
		<div class="zr-history-pagination" data-zr-history-pagination>
			<button type="button" class="zr-btn zr-btn-sm" data-zr-history-prev <?php echo $page_list <= 1 ? 'disabled' : ''; ?>><?php esc_html_e( 'Previous', 'zeko-rewards' ); ?></button>
			<span class="zr-history-page-info" data-zr-history-page-info>
				<?php /* translators: 1: current page number. 2: total number of pages */ echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'zeko-rewards' ), 1, $page_list ) ); ?>
			</span>
			<button type="button" class="zr-btn zr-btn-sm" data-zr-history-next <?php echo $page_list <= 1 ? 'disabled' : ''; ?>><?php esc_html_e( 'Next', 'zeko-rewards' ); ?></button>
		</div>
	<?php endif; ?>
</div>
