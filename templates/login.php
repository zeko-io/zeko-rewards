<?php
/**
 * Template: user must log in to view their rewards.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="zr-login">
	<p><?php esc_html_e( 'Log in to view your rewards.', 'zeko-rewards' ); ?></p>
	<a class="zr-btn" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
		<?php esc_html_e( 'Log in', 'zeko-rewards' ); ?>
	</a>
</div>
