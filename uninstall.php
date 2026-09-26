<?php
/**
 * Uninstall script for Zeko Rewards.
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Cleans up rewards-owned tables, user meta, plugin options, and scheduled
 * cron events. Shared ecosystem data is kept.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = $wpdb->prefix;

// Drop rewards-owned tables.
$tables = array(
	$prefix . 'zeko_rewards_events',
	$prefix . 'zeko_rewards_badges',
	$prefix . 'zeko_rewards_user_badges',
	$prefix . 'zeko_rewards_redemptions',
	$prefix . 'zeko_rewards_notifications',
	$prefix . 'zeko_rewards_catalog',
	$prefix . 'zeko_rewards_catalog_redemptions',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete rewards-owned user meta (tier, streaks, expiry window). Never a bare
// `zeko_%` wildcard, which would wipe other ecosystem modules' meta.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'DELETE FROM ' . $wpdb->usermeta . " WHERE meta_key LIKE 'zeko_rewards\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// Delete plugin options.
$options = array(
	'zeko_rewards_settings',
	'zeko_rewards_config_override',
	'zeko_rewards_db_version',
	'zeko_rewards_menu_version',
	'zeko_rewards_pages_created',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear all scheduled cron events.
wp_clear_scheduled_hook( 'zeko_rewards_expire' );
