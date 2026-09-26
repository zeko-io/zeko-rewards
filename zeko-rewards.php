<?php
/**
 * Plugin Name:       Zeko Rewards
 * Plugin URI:        https://ozconsultz.com/zeko-rewards
 * Description:       Cross-module rewards engine for the Zeko ecosystem: activity points, achievement badges, tiers, leaderboards, redemptions, and reward notifications — listening to every module and the theme.
 * Version:           1.1.0
 * Author:            Zeko Team
 * Author URI:        https://ozconsultz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zeko-rewards
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      7.1.2
 *
 * @package Zeko_ZEKO_REWARDS
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ZEKO_REWARDS_VERSION' ) ) {
	define( 'ZEKO_REWARDS_VERSION', '1.1.0' );
}

if ( ! defined( 'ZEKO_REWARDS_PLUGIN_PATH' ) ) {
	define( 'ZEKO_REWARDS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ZEKO_REWARDS_PLUGIN_URL' ) ) {
	define( 'ZEKO_REWARDS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ZEKO_REWARDS_URL' ) ) {
	define( 'ZEKO_REWARDS_URL', ZEKO_REWARDS_PLUGIN_URL );
}

if ( ! defined( 'ZEKO_REWARDS_PLUGIN_BASENAME' ) ) {
	define( 'ZEKO_REWARDS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'ZEKO_REWARDS_DB_VERSION' ) ) {
	define( 'ZEKO_REWARDS_DB_VERSION', '1.1.1' );
}

require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/db/class-zeko-rewards-db.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/class-zeko-rewards-pay.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/class-zeko-rewards-engine.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/class-zeko-rewards-ajax.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/class-zeko-rewards-rest.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/class-zeko-rewards-ecosystem.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/class-zeko-rewards-admin.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/class-zeko-rewards.php';
require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/privacy/class-zeko-rewards-privacy.php';

/**
 * Zeko rewards init.
 */
function zeko_rewards_init() {
	load_plugin_textdomain( 'zeko-rewards', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	$instance = Zeko_Rewards::instance();

	// One-time schema upgrade (new installs, or upgrades after a version bump).
	$installed = get_option( 'zeko_rewards_db_version', '0' );
	if ( version_compare( $installed, ZEKO_REWARDS_DB_VERSION, '<' ) ) {
		$instance->get_db()->create_tables();
		$instance->get_db()->seed_default_badges();
		update_option( 'zeko_rewards_db_version', ZEKO_REWARDS_DB_VERSION );
	}

	return $instance;
}
add_action( 'plugins_loaded', 'zeko_rewards_init' );

if ( ! function_exists( 'zeko_rewards' ) ) {
	/**
	 * Zeko rewards.
	 */
	function zeko_rewards() {
		return Zeko_Rewards::instance();
	}
}

/**
 * Zeko rewards activate.
 */
function zeko_rewards_activate() {
	require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/db/class-zeko-rewards-db.php';
	$db = new Zeko_Rewards_DB();
	$db->create_tables();
	$db->seed_default_badges();

	zeko_rewards_create_shortcode_pages();
	flush_rewrite_rules();

	if ( ! wp_next_scheduled( 'zeko_rewards_expire' ) ) {
		wp_schedule_event( time(), 'daily', 'zeko_rewards_expire' );
	}
}

/**
 * Zeko rewards deactivate.
 */
function zeko_rewards_deactivate() {
	$ts = wp_next_scheduled( 'zeko_rewards_expire' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'zeko_rewards_expire' );
	}
}

/**
 * Zeko rewards uninstall.
 */
function zeko_rewards_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		return;
	}

	require_once ZEKO_REWARDS_PLUGIN_PATH . 'includes/db/class-zeko-rewards-db.php';
	$db = new Zeko_Rewards_DB();
	$db->drop_tables();

	// Clear the daily expiry cron.
	$ts = wp_next_scheduled( 'zeko_rewards_expire' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'zeko_rewards_expire' );
	}

	// Remove plugin options.
	$options = array(
		'zeko_rewards_db_version',
		'zeko_rewards_settings',
		'zeko_rewards_config_override',
		'zeko_rewards_pages_created',
		'zeko_rewards_menu_version',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove shortcode pages (only pages this plugin created — never any.
	// arbitrary page matched by slug alone).
	if ( function_exists( 'zeko_delete_plugin_pages' ) ) {
		zeko_delete_plugin_pages( 'rewards', array( 'rewards', 'rewards-leaderboard', 'rewards-badges', 'rewards-catalog' ) );
	}

	// Remove rewards-specific user meta only (never a blanket zeko_% wipe —.
	// pay wallet bindings, mentor/love keys, and other modules share that.
	// namespace).
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'zeko_rewards_%'" );
}

/**
 * Purge a deleted user's rewards rows so no orphaned points, badges or
 * notifications survive (and leaderboards stay clean).
 *
 * @param int $user_id User id.
 */
function zeko_rewards_delete_user( int $user_id ) {
	if ( $user_id <= 0 ) {
		return;
	}

	$db = zeko_rewards()->get_db();

	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->get_table_events()} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->get_table_user_badges()} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->get_table_redemptions()} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->get_table_catalog_redemptions()} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->get_table_notifications()} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user_id, 'zeko_rewards_%' ) );
}

/**
 * Zeko rewards create shortcode pages.
 */
function zeko_rewards_create_shortcode_pages() {
	$pages = array(
		'rewards'             => array(
			'title'   => __( 'Rewards', 'zeko-rewards' ),
			'content' => '[zeko_rewards]',
		),
		'rewards-leaderboard' => array(
			'title'   => __( 'Rewards Leaderboard', 'zeko-rewards' ),
			'content' => '[zeko_rewards_leaderboard]',
		),
		'rewards-badges'      => array(
			'title'   => __( 'Rewards Badges', 'zeko-rewards' ),
			'content' => '[zeko_rewards_badges]',
		),
		'rewards-catalog'     => array(
			'title'   => __( 'Rewards Catalog', 'zeko-rewards' ),
			'content' => '[zeko_rewards_catalog]',
		),
	);

	foreach ( $pages as $slug => $page ) {
		$existing = class_exists( 'Zeko_Core_Helpers' )
			? Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug )
			: get_page_by_path( $slug );
		if ( ! $existing ) {
			$result = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => $slug,
				)
			);
			if ( is_wp_error( $result ) ) {
				error_log( 'Zeko Rewards: Failed to create page "' . $slug . '": ' . $result->get_error_message() );
				continue;
			}
			if ( function_exists( 'zeko_mark_plugin_page' ) ) {
				zeko_mark_plugin_page( $result, 'rewards' );
			}
			$existing = get_post( $result );
		}

		if ( $existing ) {
			update_option( 'zeko_rewards_' . $slug . '_page_id', (int) $existing->ID );
		}
	}
}

/**
 * Resolve the permalink for a Zeko Rewards page by its slug.
 * Delegates to the ecosystem page-URL registry (Zeko Core) when present;
 * the local resolution is the fallback so rewards still works without it.
 *
 * @return string
 * @param string $slug Page slug (e.g. 'rewards-leaderboard').
 */
function zeko_rewards_page_url( string $slug ): string {
	if ( class_exists( 'Zeko_Core_Helpers' ) && method_exists( 'Zeko_Core_Helpers', 'get_page_url' ) ) {
		return Zeko_Core_Helpers::get_instance()->get_page_url( 'rewards', $slug );
	}

	$page_id = (int) get_option( 'zeko_rewards_' . $slug . '_page_id', 0 );

	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		return get_permalink( $page_id );
	}

	$page = class_exists( 'Zeko_Core_Helpers' )
		? Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug )
		: get_page_by_path( $slug );
	if ( $page ) {
		update_option( 'zeko_rewards_' . $slug . '_page_id', (int) $page->ID );
		return get_permalink( $page );
	}

	return home_url( '/' . $slug . '/' );
}

/**
 * Zeko rewards maybe create pages.
 */
function zeko_rewards_maybe_create_pages() {
	if ( ! get_option( 'zeko_rewards_pages_created', false ) ) {
		zeko_rewards_create_shortcode_pages();
		update_option( 'zeko_rewards_pages_created', true );
	}
}

/**
 * Rewards settings.
 * Stored in the zeko_rewards_settings option, filterable via
 * 'zeko_rewards_settings'. Falls back to defaults when unset.
 *
 * @return array{enable_redemption: int, points_per_dollar: int, min_redeem_points: int, expire_days: int, streak_enabled: int, streak_bonus_every: int, streak_bonus: int, streak_multiplier: int, streak_multiplier_days: int}
 */
function zeko_rewards_get_settings(): array {
	$settings = get_option( 'zeko_rewards_settings', array() );

	$defaults = array(
		'enable_redemption'      => 1,
		'points_per_dollar'      => 100,
		'min_redeem_points'      => 100,
		'expire_days'            => 365,
		'streak_enabled'         => 1,
		'streak_bonus_every'     => 7,
		'streak_bonus'           => 25,
		'streak_multiplier'      => 0,
		'streak_multiplier_days' => 7,
	);

	return apply_filters( 'zeko_rewards_settings', wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults ) );
}

/**
 * Point awards per activity action.
 * Filterable via 'zeko_rewards_config'.
 *
 * @return array
 */
function zeko_rewards_get_config(): array {
	$config = array(
		'signup'                       => array(
			'points' => 50,
			'module' => 'core',
			'label'  => __( 'Welcome to Zeko', 'zeko-rewards' ),
			'once'   => true,
		),
		'love_match'                   => array(
			'points' => 25,
			'module' => 'love',
			'label'  => __( 'Got a dating match', 'zeko-rewards' ),
			'once'   => false,
		),
		'love_date'                    => array(
			'points' => 20,
			'module' => 'love',
			'label'  => __( 'Scheduled a date', 'zeko-rewards' ),
			'once'   => false,
		),
		'job_posted'                   => array(
			'points' => 30,
			'module' => 'jobs',
			'label'  => __( 'Posted a job', 'zeko-rewards' ),
			'once'   => false,
		),
		'job_boosted'                  => array(
			'points' => 10,
			'module' => 'jobs',
			'label'  => __( 'Boosted a job', 'zeko-rewards' ),
			'once'   => false,
		),
		'freelance_project_posted'     => array(
			'points' => 30,
			'module' => 'freelance',
			'label'  => __( 'Posted a freelance project', 'zeko-rewards' ),
			'once'   => false,
		),
		'freelance_bid_placed'         => array(
			'points' => 15,
			'module' => 'freelance',
			'label'  => __( 'Placed a freelance bid', 'zeko-rewards' ),
			'once'   => false,
		),
		'freelance_contract_won'       => array(
			'points' => 100,
			'module' => 'freelance',
			'label'  => __( 'Won a freelance contract', 'zeko-rewards' ),
			'once'   => false,
		),
		'freelance_milestone_approved' => array(
			'points' => 20,
			'module' => 'freelance',
			'label'  => __( 'Milestone approved', 'zeko-rewards' ),
			'once'   => false,
		),
		'course_enrolled'              => array(
			'points' => 10,
			'module' => 'learn',
			'label'  => __( 'Enrolled in a course', 'zeko-rewards' ),
			'once'   => false,
		),
		'course_completed'             => array(
			'points' => 100,
			'module' => 'learn',
			'label'  => __( 'Completed a course', 'zeko-rewards' ),
			'once'   => false,
		),
		'mentor_program'               => array(
			'points' => 15,
			'module' => 'mentor',
			'label'  => __( 'Joined a mentorship program', 'zeko-rewards' ),
			'once'   => false,
		),
		'mentor_match'                 => array(
			'points' => 20,
			'module' => 'mentor',
			'label'  => __( 'Matched with a mentor', 'zeko-rewards' ),
			'once'   => false,
		),
		'mentor_verified'              => array(
			'points' => 75,
			'module' => 'mentor',
			'label'  => __( 'Became a verified mentor', 'zeko-rewards' ),
			'once'   => false,
		),
		'mentor_review'                => array(
			'points' => 10,
			'module' => 'mentor',
			'label'  => __( 'Left a session review', 'zeko-rewards' ),
			'once'   => false,
		),
		'shop_order'                   => array(
			'points' => 20,
			'module' => 'shop',
			'label'  => __( 'Placed an order', 'zeko-rewards' ),
			'once'   => false,
		),
		'qa_question'                  => array(
			'points' => 10,
			'module' => 'qa',
			'label'  => __( 'Asked a question', 'zeko-rewards' ),
			'once'   => false,
		),
		'qa_answer'                    => array(
			'points' => 15,
			'module' => 'qa',
			'label'  => __( 'Answered a question', 'zeko-rewards' ),
			'once'   => false,
		),
		'qa_answer_accepted'           => array(
			'points' => 25,
			'module' => 'qa',
			'label'  => __( 'Had an answer accepted', 'zeko-rewards' ),
			'once'   => false,
		),
		'ai_conversation'              => array(
			'points' => 5,
			'module' => 'ai',
			'label'  => __( 'Chatted with the AI assistant', 'zeko-rewards' ),
			'once'   => false,
		),
		'referral_signup'              => array(
			'points' => 50,
			'module' => 'core',
			'label'  => __( 'Referral signup', 'zeko-rewards' ),
			'once'   => false,
		),
		'referral_bonus'               => array(
			'points' => 25,
			'module' => 'core',
			'label'  => __( 'Referred by a friend', 'zeko-rewards' ),
			'once'   => false,
		),
		'badge_unlock'                 => array(
			'points'   => 0,
			'module'   => 'core',
			'label'    => __( 'Badge unlocked', 'zeko-rewards' ),
			'once'     => true,
			'internal' => true,
		),
		'streak_milestone'             => array(
			'points'   => 0,
			'module'   => 'core',
			'label'    => __( 'Daily streak bonus', 'zeko-rewards' ),
			'once'     => false,
			'internal' => true,
		),
		'catalog_redeem'               => array(
			'points'   => 0,
			'module'   => 'core',
			'label'    => __( 'Reward catalog redemption', 'zeko-rewards' ),
			'once'     => false,
			'internal' => true,
		),
		'catalog_refund'               => array(
			'points'   => 0,
			'module'   => 'core',
			'label'    => __( 'Reward redemption refund', 'zeko-rewards' ),
			'once'     => false,
			'internal' => true,
		),
		'manual_adjust'                => array(
			'points'   => 0,
			'module'   => 'core',
			'label'    => __( 'Manual adjustment', 'zeko-rewards' ),
			'once'     => false,
			'internal' => true,
		),
	);

	// Apply admin-configured point overrides (stored as an option by the.
	// settings page) so edits take effect across the whole site.
	$override = get_option( 'zeko_rewards_config_override', array() );
	if ( is_array( $override ) ) {
		foreach ( $override as $action => $points ) {
			if ( isset( $config[ $action ] ) && ! empty( $config[ $action ]['internal'] ) ) {
				continue;
			}
			if ( isset( $config[ $action ] ) ) {
				$config[ $action ]['points'] = absint( $points );
			}
		}
	}

	return apply_filters( 'zeko_rewards_config', $config );
}

/**
 * Reward tiers keyed by lifetime-point threshold.
 * Filterable via 'zeko_rewards_tiers'.
 *
 * @return array<string,array{threshold:int,label:string}>
 */
function zeko_rewards_get_tiers(): array {
	$tiers = array(
		'bronze'   => array(
			'threshold' => 0,
			'label'     => __( 'Bronze', 'zeko-rewards' ),
		),
		'silver'   => array(
			'threshold' => 500,
			'label'     => __( 'Silver', 'zeko-rewards' ),
		),
		'gold'     => array(
			'threshold' => 2000,
			'label'     => __( 'Gold', 'zeko-rewards' ),
		),
		'platinum' => array(
			'threshold' => 5000,
			'label'     => __( 'Platinum', 'zeko-rewards' ),
		),
		'diamond'  => array(
			'threshold' => 10000,
			'label'     => __( 'Diamond', 'zeko-rewards' ),
		),
	);

	return apply_filters( 'zeko_rewards_tiers', $tiers );
}

register_activation_hook( __FILE__, 'zeko_rewards_activate' );
register_deactivation_hook( __FILE__, 'zeko_rewards_deactivate' );
register_uninstall_hook( __FILE__, 'zeko_rewards_uninstall' );
add_action( 'delete_user', 'zeko_rewards_delete_user', 10, 1 );
