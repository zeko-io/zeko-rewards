<?php
/**
 * Zeko Rewards — WordPress personal-data exporter and eraser.
 *
 * Registers with Tools > Export Personal Data / Erase Personal Data so site
 * owners can fulfil data-protection requests for reward point events, earned
 * badges, point redemptions (cash-out and catalog) and reward notifications.
 * All reward rows are per-user preference/ledger records, so erasure deletes
 * them outright.
 *
 * Point accrual is not age-purged automatically: reward events carry an
 * expires_at driven by the module's own expiry settings (class-zeko-rewards-
 * engine.php / cron), and deleting aged events without rebalancing would
 * corrupt recomputed point totals — the plan's "rewards events" retention is
 * therefore satisfied by the expiry + read-notification pruning the module
 * already performs, documented in the audit row 12 notes.
 *
 * Table schemas byte-verified 2026-09-25 against class-zeko-rewards-db.php DDL:
 *   {prefix}zeko_rewards_events                user_id / action / points / expires_at
 *   {prefix}zeko_rewards_user_badges           user_id / badge_id / awarded_at
 *   {prefix}zeko_rewards_redemptions           user_id / points / amount / status
 *   {prefix}zeko_rewards_notifications         user_id / type / message / is_read
 *   {prefix}zeko_rewards_catalog_redemptions   user_id / item_id / points / status
 *   {prefix}zeko_rewards_badges / catalog      global catalogs, no user linkage
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the exporter, eraser and retention-table callbacks.
 */
function zeko_rewards_privacy_register(): void {
	add_filter( 'wp_privacy_personal_data_exporters', 'zeko_rewards_privacy_register_exporter' );
	add_filter( 'wp_privacy_personal_data_erasers', 'zeko_rewards_privacy_register_eraser' );
}
add_action( 'init', 'zeko_rewards_privacy_register', 11 );

/**
 * Register the personal-data exporter.
 *
 * @param array $exporters Exporters.
 */
function zeko_rewards_privacy_register_exporter( array $exporters ): array {
	$exporters['zeko-rewards'] = array(
		'exporter_friendly_name' => __( 'Zeko Rewards data', 'zeko-rewards' ),
		'callback'               => 'zeko_rewards_privacy_export',
	);
	return $exporters;
}

/**
 * Register the personal-data eraser.
 *
 * @param array $erasers Erasers.
 */
function zeko_rewards_privacy_register_eraser( array $erasers ): array {
	$erasers['zeko-rewards'] = array(
		'eraser_friendly_name' => __( 'Zeko Rewards data', 'zeko-rewards' ),
		'callback'             => 'zeko_rewards_privacy_erase',
	);
	return $erasers;
}

/**
 * Get a prepared DB instance (null when the plugin is not active).
 */
function zeko_rewards_privacy_db(): ?Zeko_Rewards_DB {
	if ( ! class_exists( 'Zeko_Rewards_DB' ) ) {
		return null;
	}
	return new Zeko_Rewards_DB();
}

/**
 * Whether a table exists (guards every touch of a table).
 *
 * @param string $table Table.
 */
function zeko_rewards_privacy_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Export a user's Zeko Rewards data, 20 rows per table per page.
 *
 * @return array{data: array, done: bool}
 * @param string $email_address User who requested the export.
 * @param int    $page Export page (batching).
 */
function zeko_rewards_privacy_export( string $email_address, int $page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$db = zeko_rewards_privacy_db();
	if ( ! $db ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	global $wpdb;

	$user_id   = (int) $user->ID;
	$per_page  = 20;
	$offset    = ( max( 1, (int) $page ) - 1 ) * $per_page;
	$data      = array();
	$tables    = 0;
	$exhausted = 0;

	if ( zeko_rewards_privacy_table_exists( $db->get_table_events() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, points, action, module, reference_type, note, source, expires_at, created_at FROM {$db->get_table_events()} WHERE user_id = %d ORDER BY event_id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-rewards-events',
				'group_label' => __( 'Zeko Rewards — Point events', 'zeko-rewards' ),
				'item_id'     => 'zeko-rewards-event-' . (int) $row->event_id,
				'data'        => array(
					array(
						'name'  => __( 'Points', 'zeko-rewards' ),
						'value' => (string) $row->points,
					),
					array(
						'name'  => __( 'Action', 'zeko-rewards' ),
						'value' => (string) $row->action,
					),
					array(
						'name'  => __( 'Module', 'zeko-rewards' ),
						'value' => (string) $row->module,
					),
					array(
						'name'  => __( 'Reference type', 'zeko-rewards' ),
						'value' => (string) $row->reference_type,
					),
					array(
						'name'  => __( 'Note', 'zeko-rewards' ),
						'value' => (string) $row->note,
					),
					array(
						'name'  => __( 'Source', 'zeko-rewards' ),
						'value' => (string) $row->source,
					),
					array(
						'name'  => __( 'Expires at', 'zeko-rewards' ),
						'value' => (string) $row->expires_at,
					),
					array(
						'name'  => __( 'Created at', 'zeko-rewards' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_rewards_privacy_table_exists( $db->get_table_user_badges() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.badge_id, b.awarded_at, g.name AS badge_name FROM {$db->get_table_user_badges()} b LEFT JOIN {$db->get_table_badges()} g ON g.badge_id = b.badge_id WHERE b.user_id = %d ORDER BY b.id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-rewards-badges',
				'group_label' => __( 'Zeko Rewards — Badges', 'zeko-rewards' ),
				'item_id'     => 'zeko-rewards-badge-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Badge', 'zeko-rewards' ),
						'value' => (string) $row->badge_name,
					),
					array(
						'name'  => __( 'Badge ID', 'zeko-rewards' ),
						'value' => (string) $row->badge_id,
					),
					array(
						'name'  => __( 'Awarded at', 'zeko-rewards' ),
						'value' => (string) $row->awarded_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_rewards_privacy_table_exists( $db->get_table_redemptions() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, points, amount, rate, status, reference_id, created_at FROM {$db->get_table_redemptions()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-rewards-redemptions',
				'group_label' => __( 'Zeko Rewards — Point redemptions', 'zeko-rewards' ),
				'item_id'     => 'zeko-rewards-redemption-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Points', 'zeko-rewards' ),
						'value' => (string) $row->points,
					),
					array(
						'name'  => __( 'Amount', 'zeko-rewards' ),
						'value' => (string) $row->amount,
					),
					array(
						'name'  => __( 'Rate', 'zeko-rewards' ),
						'value' => (string) $row->rate,
					),
					array(
						'name'  => __( 'Status', 'zeko-rewards' ),
						'value' => (string) $row->status,
					),
					array(
						'name'  => __( 'Reference', 'zeko-rewards' ),
						'value' => (string) $row->reference_id,
					),
					array(
						'name'  => __( 'Created at', 'zeko-rewards' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_rewards_privacy_table_exists( $db->get_table_catalog_redemptions() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_id, points, status, admin_note, created_at FROM {$db->get_table_catalog_redemptions()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-rewards-catalog-redemptions',
				'group_label' => __( 'Zeko Rewards — Catalog redemptions', 'zeko-rewards' ),
				'item_id'     => 'zeko-rewards-catalog-redemption-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Item ID', 'zeko-rewards' ),
						'value' => (string) $row->item_id,
					),
					array(
						'name'  => __( 'Points', 'zeko-rewards' ),
						'value' => (string) $row->points,
					),
					array(
						'name'  => __( 'Status', 'zeko-rewards' ),
						'value' => (string) $row->status,
					),
					array(
						'name'  => __( 'Admin note', 'zeko-rewards' ),
						'value' => (string) $row->admin_note,
					),
					array(
						'name'  => __( 'Created at', 'zeko-rewards' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	if ( zeko_rewards_privacy_table_exists( $db->get_table_notifications() ) ) {
		++$tables;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, message, reference, is_read, created_at FROM {$db->get_table_notifications()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-rewards-notifications',
				'group_label' => __( 'Zeko Rewards — Notifications', 'zeko-rewards' ),
				'item_id'     => 'zeko-rewards-notification-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Type', 'zeko-rewards' ),
						'value' => (string) $row->type,
					),
					array(
						'name'  => __( 'Message', 'zeko-rewards' ),
						'value' => (string) $row->message,
					),
					array(
						'name'  => __( 'Reference', 'zeko-rewards' ),
						'value' => (string) $row->reference,
					),
					array(
						'name'  => __( 'Read', 'zeko-rewards' ),
						'value' => (string) $row->is_read,
					),
					array(
						'name'  => __( 'Created at', 'zeko-rewards' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}
		if ( count( $rows ) < $per_page ) {
			++$exhausted;
		}
	}

	return array(
		'data' => $data,
		'done' => $exhausted === $tables,
	);
}

/**
 * Erase a user's Zeko Rewards data.
 * Every reward row is a per-user ledger/preference record, drained 20 per
 * table per pass until done. No community content is anonymized here: badges
 * and catalog are global catalogs with no user linkage, and reward events are
 * the user's own point ledger.
 *
 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
 * @param string $email_address User who requested erasure.
 * @param int    $_page page.
 */
function zeko_rewards_privacy_erase( string $email_address, int $_page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$db = zeko_rewards_privacy_db();
	if ( ! $db ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	global $wpdb;

	$user_id = (int) $user->ID;
	$removed = 0;

	foreach ( array(
		$db->get_table_events(),
		$db->get_table_user_badges(),
		$db->get_table_redemptions(),
		$db->get_table_catalog_redemptions(),
		$db->get_table_notifications(),
	) as $table ) {
		if ( ! zeko_rewards_privacy_table_exists( $table ) ) {
			continue;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d LIMIT %d",
				$user_id,
				20
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Remove any rewards-specific user meta owned by this user.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$removed += (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
			$user_id,
			'zeko_rewards_%'
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	$remaining = 0;
	foreach ( array(
		$db->get_table_events(),
		$db->get_table_user_badges(),
		$db->get_table_redemptions(),
		$db->get_table_catalog_redemptions(),
		$db->get_table_notifications(),
	) as $table ) {
		if ( ! zeko_rewards_privacy_table_exists( $table ) ) {
			continue;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => 0,
		'messages'       => array(),
		'done'           => 0 === $remaining,
	);
}
