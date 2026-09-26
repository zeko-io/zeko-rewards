<?php
/**
 * Zeko Rewards database layer.
 *
 * Owns the point ledger (events), badge definitions (badges), earned badges
 * (user_badges), wallet redemptions (redemptions), reward notifications,
 * the reward catalog (catalog) and catalog redemption requests.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards_DB. */
class Zeko_Rewards_DB {

	/**
	 * Wpdb.
	 *
	 * @var mixed Wpdb.
	 */
	private $wpdb;

	/**
	 * Table events.
	 *
	 * @var string Table events.
	 */
	private string $table_events;
	/**
	 * Table badges.
	 *
	 * @var string Table badges.
	 */
	private string $table_badges;
	/**
	 * Table user badges.
	 *
	 * @var string Table user badges.
	 */
	private string $table_user_badges;
	/**
	 * Table redemptions.
	 *
	 * @var string Table redemptions.
	 */
	private string $table_redemptions;
	/**
	 * Table notifications.
	 *
	 * @var string Table notifications.
	 */
	private string $table_notifications;
	/**
	 * Table catalog.
	 *
	 * @var string Table catalog.
	 */
	private string $table_catalog;
	/**
	 * Table catalog redemptions.
	 *
	 * @var string Table catalog redemptions.
	 */
	private string $table_catalog_redemptions;

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;

		$p = $wpdb->prefix;

		$this->table_events              = $p . 'zeko_rewards_events';
		$this->table_badges              = $p . 'zeko_rewards_badges';
		$this->table_user_badges         = $p . 'zeko_rewards_user_badges';
		$this->table_redemptions         = $p . 'zeko_rewards_redemptions';
		$this->table_notifications       = $p . 'zeko_rewards_notifications';
		$this->table_catalog             = $p . 'zeko_rewards_catalog';
		$this->table_catalog_redemptions = $p . 'zeko_rewards_catalog_redemptions';
	}

	/**
	 * Table events.
	 */
	public function get_table_events(): string {
		return $this->table_events; }
	/**
	 * Table badges.
	 */
	public function get_table_badges(): string {
		return $this->table_badges; }
	/**
	 * Table user badges.
	 */
	public function get_table_user_badges(): string {
		return $this->table_user_badges; }
	/**
	 * Table redemptions.
	 */
	public function get_table_redemptions(): string {
		return $this->table_redemptions; }
	/**
	 * Table notifications.
	 */
	public function get_table_notifications(): string {
		return $this->table_notifications; }
	/**
	 * Table catalog.
	 */
	public function get_table_catalog(): string {
		return $this->table_catalog; }
	/**
	 * Table catalog redemptions.
	 */
	public function get_table_catalog_redemptions(): string {
		return $this->table_catalog_redemptions; }

	// ═══════════════════════════════════════════════════════════════.
	// SCHEMA.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Create tables.
	 */
	public function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $this->wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE {$this->table_events} (
			event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			points int(11) NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL DEFAULT '',
			module varchar(32) NOT NULL DEFAULT '',
			reference_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reference_type varchar(32) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			source varchar(32) NOT NULL DEFAULT 'activity',
			expires_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (event_id),
			KEY user_id (user_id),
			KEY action (action),
			KEY module (module),
			KEY expires_at (expires_at),
			KEY user_action_ref (user_id, action, reference_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$this->table_badges} (
			badge_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL DEFAULT '',
			name varchar(100) NOT NULL DEFAULT '',
			description text NULL,
			icon varchar(64) NOT NULL DEFAULT 'awards',
			module varchar(32) NOT NULL DEFAULT 'core',
			criteria_type varchar(32) NOT NULL DEFAULT 'points_total',
			criteria_module varchar(32) NOT NULL DEFAULT '',
			criteria_action varchar(64) NOT NULL DEFAULT '',
			criteria_value int(11) NOT NULL DEFAULT 1,
			points int(11) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			UNIQUE KEY slug (slug),
			PRIMARY KEY  (badge_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$this->table_user_badges} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			badge_id bigint(20) unsigned NOT NULL DEFAULT 0,
			awarded_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			UNIQUE KEY user_badge (user_id, badge_id),
			KEY badge_id (badge_id),
			PRIMARY KEY  (id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$this->table_redemptions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			points int(11) NOT NULL DEFAULT 0,
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			rate int(11) NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'completed',
			reference_id varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			KEY user_id (user_id),
			PRIMARY KEY  (id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$this->table_notifications} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(32) NOT NULL DEFAULT 'reward_earned',
			message text NULL,
			reference varchar(255) NOT NULL DEFAULT '',
			is_read tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			KEY user_id (user_id),
			KEY unread (user_id, is_read),
			PRIMARY KEY  (id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$this->table_catalog} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(150) NOT NULL DEFAULT '',
			description text NULL,
			points_cost int(11) NOT NULL DEFAULT 0,
			stock int(11) NOT NULL DEFAULT -1,
			image_url varchar(255) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			KEY is_active (is_active),
			PRIMARY KEY  (id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$this->table_catalog_redemptions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			points int(11) NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'pending',
			admin_note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			KEY user_id (user_id),
			KEY status (status),
			PRIMARY KEY  (id)
		) {$charset};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	/**
	 * Drop every rewards table. Called on uninstall.
	 */
	public function drop_tables(): void {
		$tables = array(
			$this->table_events,
			$this->table_badges,
			$this->table_user_badges,
			$this->table_redemptions,
			$this->table_notifications,
			$this->table_catalog,
			$this->table_catalog_redemptions,
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( $tables as $table ) {
			$this->wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// EVENTS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Log event.
	 *
	 * @param int     $user_id User id.
	 * @param int     $points Points.
	 * @param string  $action Action.
	 * @param string  $module Module.
	 * @param int     $reference_id Reference id.
	 * @param string  $reference_type Reference type.
	 * @param string  $note Note.
	 * @param string  $source Source.
	 * @param ?string $expires_at Expires at.
	 */
	public function log_event( int $user_id, int $points, string $action, string $module, int $reference_id = 0, string $reference_type = '', string $note = '', string $source = 'activity', ?string $expires_at = null ): int {
		$this->wpdb->insert(
			$this->table_events,
			array(
				'user_id'        => $user_id,
				'points'         => $points,
				'action'         => sanitize_key( $action ),
				'module'         => sanitize_key( $module ),
				'reference_id'   => $reference_id,
				'reference_type' => sanitize_key( $reference_type ),
				'note'           => sanitize_text_field( $note ),
				'source'         => sanitize_key( $source ),
				'expires_at'     => $expires_at,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Event exists.
	 *
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 * @param int    $reference_id Reference id.
	 */
	public function event_exists( int $user_id, string $action, int $reference_id = 0 ): bool {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_events} WHERE user_id = %d AND action = %s AND reference_id = %d",
				$user_id,
				$action,
				$reference_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $found > 0;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * User has action.
	 *
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 */
	public function user_has_action( int $user_id, string $action ): bool {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_events} WHERE user_id = %d AND action = %s",
				$user_id,
				$action
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $found > 0;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Available (spendable) points: unexpired balance.
	 * Spends never expire while their earning events can, so the raw ledger
	 * sum can dip below zero after expiry. Clamp at 0 — a user must never be
	 * shown (or allowed to spend from) a negative balance.
	 *
	 * @param int $user_id User id.
	 */
	public function user_points( int $user_id ): int {
		$sum = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$this->table_events}
				WHERE user_id = %d AND ( expires_at IS NULL OR expires_at > UTC_TIMESTAMP() )",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return max( 0, $sum );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Lifetime points: everything positive and not yet expired.
	 *
	 * @param int $user_id User id.
	 */
	public function user_lifetime_points( int $user_id ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$this->table_events}
				WHERE user_id = %d AND points > 0 AND ( expires_at IS NULL OR expires_at > UTC_TIMESTAMP() )",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Module points.
	 *
	 * @param int    $user_id User id.
	 * @param string $module Module.
	 */
	public function module_points( int $user_id, string $module ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$this->table_events}
				WHERE user_id = %d AND module = %s AND ( expires_at IS NULL OR expires_at > UTC_TIMESTAMP() )",
				$user_id,
				$module
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Action count.
	 *
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 */
	public function action_count( int $user_id, string $action ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_events} WHERE user_id = %d AND action = %s",
				$user_id,
				$action
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Events.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 */
	public function get_events( int $user_id, int $limit = 20, int $offset = 0 ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_events} WHERE user_id = %d ORDER BY event_id DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Events filtered.
	 *
	 * @param int    $user_id User id.
	 * @param string $module Module.
	 * @param string $action Action.
	 * @param int    $limit Limit.
	 * @param int    $offset Offset.
	 */
	public function get_events_filtered( int $user_id, string $module = '', string $action = '', int $limit = 15, int $offset = 0 ): array {
		$where  = array( 'user_id = %d' );
		$params = array( $user_id );

		if ( '' !== $module ) {
			$where[]  = 'module = %s';
			$params[] = $module;
		}
		if ( '' !== $action ) {
			$where[]  = 'action = %s';
			$params[] = $action;
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = $limit;
		$params[]  = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				"SELECT * FROM {$this->table_events} WHERE {$where_sql} ORDER BY event_id DESC LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Count events filtered.
	 *
	 * @param int    $user_id User id.
	 * @param string $module Module.
	 * @param string $action Action.
	 */
	public function count_events_filtered( int $user_id, string $module = '', string $action = '' ): int {
		$where  = array( 'user_id = %d' );
		$params = array( $user_id );

		if ( '' !== $module ) {
			$where[]  = 'module = %s';
			$params[] = $module;
		}
		if ( '' !== $action ) {
			$where[]  = 'action = %s';
			$params[] = $action;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_events} WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				...$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Recent manual adjustments.
	 *
	 * @param int $limit Limit.
	 */
	public function recent_manual_adjustments( int $limit = 25 ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT e.*, u.display_name
				FROM {$this->table_events} e
				LEFT JOIN {$this->wpdb->users} u ON e.user_id = u.ID
				WHERE e.action = 'manual_adjust'
				ORDER BY e.event_id DESC
				LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// ═══════════════════════════════════════════════════════════════.
	// BADGES.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Seed default badges.
	 */
	public function seed_default_badges(): void {
		$badges = apply_filters(
			'zeko_rewards_default_badges',
			array(
				array(
					'slug'           => 'first-steps',
					'name'           => 'First Steps',
					'description'    => 'Earn your first reward points.',
					'icon'           => 'flag',
					'module'         => 'core',
					'criteria_type'  => 'points_total',
					'criteria_value' => 1,
					'points'         => 5,
				),
				array(
					'slug'           => 'early-bird',
					'name'           => 'Early Bird',
					'description'    => 'Accumulate 100 lifetime points.',
					'icon'           => 'star-filled',
					'module'         => 'core',
					'criteria_type'  => 'points_total',
					'criteria_value' => 100,
					'points'         => 10,
				),
				array(
					'slug'           => 'centurion',
					'name'           => 'Centurion',
					'description'    => 'Accumulate 1,000 lifetime points.',
					'icon'           => 'shield-alt',
					'module'         => 'core',
					'criteria_type'  => 'points_total',
					'criteria_value' => 1000,
					'points'         => 50,
				),
				array(
					'slug'           => 'legend',
					'name'           => 'Legend',
					'description'    => 'Accumulate 5,000 lifetime points.',
					'icon'           => 'awards',
					'module'         => 'core',
					'criteria_type'  => 'points_total',
					'criteria_value' => 5000,
					'points'         => 200,
				),
				array(
					'slug'            => 'love-match',
					'name'            => 'Heartbreaker',
					'description'     => 'Get your first dating match.',
					'icon'            => 'heart',
					'module'          => 'love',
					'criteria_type'   => 'action_count',
					'criteria_action' => 'love_match',
					'criteria_value'  => 1,
					'points'          => 10,
				),
				array(
					'slug'            => 'job-poster',
					'name'            => 'Boss',
					'description'     => 'Post your first job.',
					'icon'            => 'briefcase',
					'module'          => 'jobs',
					'criteria_type'   => 'action_count',
					'criteria_action' => 'job_posted',
					'criteria_value'  => 1,
					'points'          => 10,
				),
				array(
					'slug'            => 'scholar',
					'name'            => 'Scholar',
					'description'     => 'Complete your first course.',
					'icon'            => 'welcome-learn-more',
					'module'          => 'learn',
					'criteria_type'   => 'action_count',
					'criteria_action' => 'course_completed',
					'criteria_value'  => 1,
					'points'          => 20,
				),
				array(
					'slug'            => 'mentor-guide',
					'name'            => 'Guide',
					'description'     => 'Become a verified mentor.',
					'icon'            => 'groups',
					'module'          => 'mentor',
					'criteria_type'   => 'action_count',
					'criteria_action' => 'mentor_verified',
					'criteria_value'  => 1,
					'points'          => 30,
				),
				array(
					'slug'            => 'shopper',
					'name'            => 'Customer',
					'description'     => 'Place your first order.',
					'icon'            => 'cart',
					'module'          => 'shop',
					'criteria_type'   => 'action_count',
					'criteria_action' => 'shop_order',
					'criteria_value'  => 1,
					'points'          => 10,
				),
			)
		);

		foreach ( $badges as $badge ) {
			$this->insert_badge( $badge );
		}
	}

	/**
	 * Insert badge.
	 *
	 * @param array $data Data.
	 */
	public function insert_badge( array $data ): int {
		$existing = $this->get_badge_by_slug( $data['slug'] ?? '' );
		if ( $existing ) {
			return (int) $existing['badge_id'];
		}

		$this->wpdb->insert(
			$this->table_badges,
			array(
				'slug'            => sanitize_key( $data['slug'] ?? '' ),
				'name'            => sanitize_text_field( $data['name'] ?? '' ),
				'description'     => sanitize_textarea_field( $data['description'] ?? '' ),
				'icon'            => sanitize_key( $data['icon'] ?? 'awards' ),
				'module'          => sanitize_key( $data['module'] ?? 'core' ),
				'criteria_type'   => sanitize_key( $data['criteria_type'] ?? 'points_total' ),
				'criteria_module' => sanitize_key( $data['criteria_module'] ?? '' ),
				'criteria_action' => sanitize_key( $data['criteria_action'] ?? '' ),
				'criteria_value'  => absint( $data['criteria_value'] ?? 1 ),
				'points'          => absint( $data['points'] ?? 0 ),
				'is_active'       => ! empty( $data['is_active'] ) ? 1 : 1,
			)
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Badges.
	 *
	 * @param bool $active_only Active only.
	 */
	public function get_badges( bool $active_only = true ): array {
		$where = $active_only ? 'WHERE is_active = 1' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table_badges} {$where} ORDER BY badge_id ASC",
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Badge by slug.
	 *
	 * @param string $slug Slug.
	 */
	public function get_badge_by_slug( string $slug ): ?array {
		if ( ! $slug ) {
			return null;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_badges} WHERE slug = %s LIMIT 1",
				$slug
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * User badges.
	 *
	 * @param int $user_id User id.
	 */
	public function get_user_badges( int $user_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT b.*, ub.awarded_at
				FROM {$this->table_user_badges} ub
				INNER JOIN {$this->table_badges} b ON ub.badge_id = b.badge_id
				WHERE ub.user_id = %d
				ORDER BY ub.awarded_at ASC",
				$user_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Award badge.
	 *
	 * @param int $user_id User id.
	 * @param int $badge_id Badge id.
	 */
	public function award_badge( int $user_id, int $badge_id ): bool {
		$exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table_user_badges} WHERE user_id = %d AND badge_id = %d",
				$user_id,
				$badge_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $exists ) {
			return false;
		}

		$this->wpdb->insert(
			$this->table_user_badges,
			array(
				'user_id'    => $user_id,
				'badge_id'   => $badge_id,
				'awarded_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);

		return true;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Badge.
	 *
	 * @param int $user_id User id.
	 * @param int $badge_id Badge id.
	 */
	public function has_badge( int $user_id, int $badge_id ): bool {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_user_badges} WHERE user_id = %d AND badge_id = %d",
				$user_id,
				$badge_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $found > 0;
	}

	// ═══════════════════════════════════════════════════════════════.
	// REDEMPTIONS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Log redemption.
	 *
	 * @param int    $user_id User id.
	 * @param int    $points Points.
	 * @param float  $amount Amount.
	 * @param int    $rate Rate.
	 * @param string $reference_id Reference id.
	 */
	public function log_redemption( int $user_id, int $points, float $amount, int $rate, string $reference_id ): int {
		$this->wpdb->insert(
			$this->table_redemptions,
			array(
				'user_id'      => $user_id,
				'points'       => $points,
				'amount'       => number_format( max( 0.0, (float) $amount ), 2, '.', '' ),
				'rate'         => $rate,
				'status'       => 'completed',
				'reference_id' => sanitize_text_field( $reference_id ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Redemptions.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	public function get_redemptions( int $user_id, int $limit = 20 ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_redemptions} WHERE user_id = %d ORDER BY id DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// ═══════════════════════════════════════════════════════════════.
	// NOTIFICATIONS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Add notification.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param string $message Message.
	 * @param string $reference Reference.
	 */
	public function add_notification( int $user_id, string $type, string $message, string $reference = '' ): int {
		$this->wpdb->insert(
			$this->table_notifications,
			array(
				'user_id'    => $user_id,
				'type'       => sanitize_key( $type ),
				'message'    => sanitize_text_field( $message ),
				'reference'  => sanitize_text_field( $reference ),
				'is_read'    => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Notifications.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	public function get_notifications( int $user_id, int $limit = 20 ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_notifications} WHERE user_id = %d ORDER BY id DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Unread notifications count.
	 *
	 * @param int $user_id User id.
	 */
	public function unread_notifications_count( int $user_id ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_notifications} WHERE user_id = %d AND is_read = 0",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Mark notifications read.
	 *
	 * @param int $user_id User id.
	 */
	public function mark_notifications_read( int $user_id ): bool {
		return (bool) $this->wpdb->update(
			$this->table_notifications,
			array( 'is_read' => 1 ),
			array(
				'user_id' => $user_id,
				'is_read' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Notification.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param string $reference Reference.
	 */
	public function has_notification( int $user_id, string $type, string $reference ): bool {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_notifications} WHERE user_id = %d AND type = %s AND reference = %s",
				$user_id,
				$type,
				$reference
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $found > 0;
	}

	/**
	 * Retention cap: keep the newest `$keep` read notifications per user and
	 * delete older read rows. Unread rows are never touched.
	 *
	 * @param int $keep Keep.
	 */
	public function prune_read_notifications( int $keep = 500 ): int {
		global $wpdb;
		$deleted = 0;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$users = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$this->table_notifications} WHERE is_read = 1" );
		foreach ( $users as $user_id ) {
			$old = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$this->table_notifications}
					 WHERE user_id = %d AND is_read = 1
					 ORDER BY id DESC LIMIT %d, 18446744073709551615",
					(int) $user_id,
					max( 0, $keep )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( empty( $old ) ) {
				continue;
			}
			$ids      = array_map( 'absint', $old );
			$deleted += (int) $wpdb->query( "DELETE FROM {$this->table_notifications} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		return $deleted;
	}

	// ═══════════════════════════════════════════════════════════════.
	// REWARD CATALOG.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Catalog.
	 *
	 * @param bool $active_only Active only.
	 * @param int  $limit Limit.
	 */
	public function get_catalog( bool $active_only = true, int $limit = 100 ): array {
		$where = $active_only ? 'WHERE is_active = 1' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_catalog} {$where} ORDER BY points_cost ASC LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Catalog item.
	 *
	 * @param int $item_id Item id.
	 */
	public function get_catalog_item( int $item_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_catalog} WHERE id = %d LIMIT 1",
				$item_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	/**
	 * Insert catalog item.
	 *
	 * @param array $data Data.
	 */
	public function insert_catalog_item( array $data ): int {
		$this->wpdb->insert(
			$this->table_catalog,
			array(
				'name'        => sanitize_text_field( $data['name'] ?? '' ),
				'description' => sanitize_textarea_field( $data['description'] ?? '' ),
				'points_cost' => max( 1, absint( $data['points_cost'] ?? 0 ) ),
				'stock'       => isset( $data['stock'] ) ? (int) $data['stock'] : -1,
				'image_url'   => esc_url_raw( $data['image_url'] ?? '' ),
				'is_active'   => empty( $data['is_active'] ) ? 0 : 1,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update catalog item.
	 *
	 * @param int   $item_id Item id.
	 * @param array $data Data.
	 */
	public function update_catalog_item( int $item_id, array $data ): bool {
		$fields = array();
		$format = array();

		foreach ( array(
			'name'        => '%s',
			'description' => '%s',
			'points_cost' => '%d',
			'stock'       => '%d',
			'image_url'   => '%s',
			'is_active'   => '%d',
		) as $field => $fmt ) {
			if ( array_key_exists( $field, $data ) ) {
				if ( 'points_cost' === $field ) {
					$fields[ $field ] = max( 1, absint( $data[ $field ] ) );
				} elseif ( 'is_active' === $field ) {
					$fields[ $field ] = empty( $data[ $field ] ) ? 0 : 1;
				} else {
					$fields[ $field ] = $data[ $field ];
				}
				$format[] = $fmt;
			}
		}

		if ( ! $fields ) {
			return false;
		}

		return (bool) $this->wpdb->update( $this->table_catalog, $fields, array( 'id' => $item_id ), $format, array( '%d' ) );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Atomically reserve one unit of a finite-stock catalog item.
	 * Uses a conditional UPDATE so concurrent redemptions can never oversell
	 * the last unit. Returns false when the item is out of stock.
	 *
	 * @return bool True if a unit was reserved.
	 * @param int $item_id Item id.
	 */
	public function reserve_catalog_stock( int $item_id ): bool {
		$reserved = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table_catalog}
				 SET stock = stock - 1,
				     is_active = IF(stock > 0, 1, 0)
				 WHERE id = %d AND stock > 0",
				$item_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (bool) $reserved;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Return one unit to a finite-stock catalog item (cancellation).
	 *
	 * @param int $item_id Item id.
	 */
	public function restore_catalog_stock( int $item_id ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table_catalog}
				 SET stock = stock + 1,
				     is_active = 1
				 WHERE id = %d AND stock >= 0",
				$item_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Delete catalog item.
	 *
	 * @param int $item_id Item id.
	 */
	public function delete_catalog_item( int $item_id ): bool {
		return (bool) $this->wpdb->delete( $this->table_catalog, array( 'id' => $item_id ), array( '%d' ) );
	}

	// ═══════════════════════════════════════════════════════════════.
	// CATALOG REDEMPTION REQUESTS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Log catalog redemption.
	 *
	 * @param int $user_id User id.
	 * @param int $item_id Item id.
	 * @param int $points Points.
	 */
	public function log_catalog_redemption( int $user_id, int $item_id, int $points ): int {
		$this->wpdb->insert(
			$this->table_catalog_redemptions,
			array(
				'user_id'    => $user_id,
				'item_id'    => $item_id,
				'points'     => $points,
				'status'     => 'pending',
				'admin_note' => '',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Catalog redemptions.
	 *
	 * @param int    $user_id User id.
	 * @param int    $limit Limit.
	 * @param string $status Status.
	 */
	public function get_catalog_redemptions( int $user_id = 0, int $limit = 50, string $status = '' ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( $user_id > 0 ) {
			$where[]  = 'r.user_id = %d';
			$params[] = $user_id;
		}
		if ( $status ) {
			$where[]  = 'r.status = %s';
			$params[] = $status;
		}
		$params[] = max( 1, $limit );

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT r.*, c.name AS item_name, u.display_name
				FROM {$this->table_catalog_redemptions} r
				LEFT JOIN {$this->table_catalog} c ON r.item_id = c.id
				LEFT JOIN {$this->wpdb->users} u ON r.user_id = u.ID
				WHERE {$where_sql}
				ORDER BY r.id DESC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Catalog redemption.
	 *
	 * @param int $id Id.
	 */
	public function get_catalog_redemption( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_catalog_redemptions} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	/**
	 * Update catalog redemption status.
	 *
	 * @param int    $id Id.
	 * @param string $status Status.
	 * @param string $admin_note Admin note.
	 */
	public function update_catalog_redemption_status( int $id, string $status, string $admin_note = '' ): bool {
		return (bool) $this->wpdb->update(
			$this->table_catalog_redemptions,
			array(
				'status'     => sanitize_key( $status ),
				'admin_note' => sanitize_text_field( $admin_note ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	// ═══════════════════════════════════════════════════════════════.
	// EXPIRATION.
	// ═══════════════════════════════════════════════════════════════.

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Events whose points expired within the last 24h (candidates for an
	 * "X points expired" notification).
	 *
	 * @return array
	 */
	public function recently_expired_events(): array {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table_events}
			WHERE points > 0
			  AND expires_at IS NOT NULL
			  AND expires_at > DATE_SUB( UTC_TIMESTAMP(), INTERVAL 24 HOUR )
			  AND expires_at <= UTC_TIMESTAMP()
			LIMIT 500",
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// ═══════════════════════════════════════════════════════════════.
	// LEADERBOARD.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Top users by lifetime points, optionally scoped to one module.
	 *
	 * @return array
	 * @param int    $limit How many rows.
	 * @param string $module Restrict to a single module ('' = all).
	 */
	public function get_leaderboard( int $limit = 10, string $module = '' ): array {
		$module_where = $module ? ' AND e.module = %s' : '';
		$params       = array();
		if ( $module ) {
			$params[] = $module;
		}
		$params[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT e.user_id,
					SUM(e.points) AS lifetime_points,
					MAX(u.display_name) AS display_name,
					COUNT(DISTINCT ub.id) AS badge_count
				FROM {$this->table_events} e
				LEFT JOIN {$this->wpdb->users} u ON e.user_id = u.ID
				LEFT JOIN {$this->table_user_badges} ub ON e.user_id = ub.user_id
				WHERE e.points > 0
				  AND ( e.expires_at IS NULL OR e.expires_at > UTC_TIMESTAMP() )
				  {$module_where}
				GROUP BY e.user_id
				ORDER BY lifetime_points DESC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Rank.
	 *
	 * @param int $user_id User id.
	 */
	public function get_rank( int $user_id ): int {
		$lifetime = $this->user_lifetime_points( $user_id );

		if ( $lifetime <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rank = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) + 1 FROM (
					SELECT e.user_id, SUM(e.points) AS total
					FROM {$this->table_events} e
					WHERE e.points > 0 AND ( e.expires_at IS NULL OR e.expires_at > UTC_TIMESTAMP() )
					GROUP BY e.user_id
					HAVING total > %d
				) AS r",
				$lifetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return $rank;
	}
}
