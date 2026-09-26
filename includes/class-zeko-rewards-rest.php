<?php
/**
 * Zeko Rewards REST API.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards_REST. */
class Zeko_Rewards_REST {

	/**
	 * Db.
	 *
	 * @var Zeko_Rewards_DB Db.
	 */
	private Zeko_Rewards_DB $db;

	private const NAMESPACE = 'zeko-rewards/v1';

	/**
	 * Construct.
	 *
	 * @param Zeko_Rewards_DB $db Db.
	 */
	public function __construct( Zeko_Rewards_DB $db ) {
		$this->db = $db;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/leaderboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'leaderboard' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'limit'  => array(
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
					'module' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'catalog' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Leaderboard.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function leaderboard( WP_REST_Request $request ): WP_REST_Response {
		$limit = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );
		$rows  = $this->db->get_leaderboard( $limit, (string) $request->get_param( 'module' ) );

		$helpers = class_exists( 'Zeko_Core_Helpers' ) ? Zeko_Core_Helpers::get_instance() : null;
		$data    = array();

		foreach ( $rows as $index => $row ) {
			$data[] = array(
				'rank'            => $index + 1,
				'user_id'         => (int) $row['user_id'],
				/* translators: %d: numeric user ID */
				'display_name'    => $row['display_name'] ? $row['display_name'] : sprintf( __( 'User #%d', 'zeko-rewards' ), (int) $row['user_id'] ),
				'lifetime_points' => (int) $row['lifetime_points'],
				'badge_count'     => (int) $row['badge_count'],
				'avatar'          => $helpers ? $helpers->get_user_avatar( (int) $row['user_id'], 40 ) : '',
				'profile_url'     => $helpers ? $helpers->get_user_profile_url( (int) $row['user_id'] ) : '',
			);
		}

		return new WP_REST_Response( array( 'leaderboard' => $data ), 200 );
	}

	/**
	 * Stats.
	 */
	public function stats(): WP_REST_Response {
		$tiers = zeko_rewards_get_tiers();

		$data = array(
			'tier_count' => count( $tiers ),
			'tiers'      => $tiers,
		);

		if ( is_user_logged_in() ) {
			$user_id    = get_current_user_id();
			$tier_key   = (string) get_user_meta( $user_id, 'zeko_rewards_tier', true );
			$data['me'] = array(
				'user_id'    => $user_id,
				'points'     => $this->db->user_points( $user_id ),
				'lifetime'   => $this->db->user_lifetime_points( $user_id ),
				'tier'       => $tier_key ? $tier_key : 'bronze',
				'badges'     => count( $this->db->get_user_badges( $user_id ) ),
				'rank'       => $this->db->get_rank( $user_id ),
				'unread'     => $this->db->unread_notifications_count( $user_id ),
				'streak'     => zeko_rewards()->get_engine()->current_streak( $user_id ),
				'redemption' => array(
					'active'            => Zeko_Rewards_Pay::active(),
					'enabled'           => (bool) zeko_rewards_get_settings()['enable_redemption'],
					'points_per_dollar' => (int) zeko_rewards_get_settings()['points_per_dollar'],
				),
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Catalog.
	 */
	public function catalog(): WP_REST_Response {
		$items = $this->db->get_catalog( true );

		$data = array();
		foreach ( $items as $item ) {
			$data[] = array(
				'id'          => (int) $item['id'],
				'name'        => $item['name'],
				'description' => $item['description'],
				'points_cost' => (int) $item['points_cost'],
				'stock'       => (int) $item['stock'],
				'image_url'   => $item['image_url'],
				'available'   => ( (int) $item['stock'] < 0 ) || (int) $item['stock'] > 0,
			);
		}

		return new WP_REST_Response( array( 'catalog' => $data ), 200 );
	}
}
