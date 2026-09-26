<?php
/**
 * Zeko Rewards AJAX handlers.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards_Ajax. */
class Zeko_Rewards_Ajax {

	/**
	 * Engine.
	 *
	 * @var Zeko_Rewards_Engine Engine.
	 */
	private Zeko_Rewards_Engine $engine;
	/**
	 * Db.
	 *
	 * @var Zeko_Rewards_DB Db.
	 */
	private Zeko_Rewards_DB $db;

	/**
	 * Construct.
	 *
	 * @param Zeko_Rewards_Engine $engine Engine.
	 * @param Zeko_Rewards_DB     $db Db.
	 */
	public function __construct( Zeko_Rewards_Engine $engine, Zeko_Rewards_DB $db ) {
		$this->engine = $engine;
		$this->db     = $db;

		add_action( 'wp_ajax_zeko_rewards_redeem', array( $this, 'redeem' ) );
		add_action( 'wp_ajax_nopriv_zeko_rewards_redeem', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_rewards_redeem_item', array( $this, 'redeem_item' ) );
		add_action( 'wp_ajax_nopriv_zeko_rewards_redeem_item', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_rewards_mark_read', array( $this, 'mark_read' ) );
		add_action( 'wp_ajax_nopriv_zeko_rewards_mark_read', array( $this, 'denied' ) );

		add_action( 'wp_ajax_zeko_rewards_filter_history', array( $this, 'filter_history' ) );
		add_action( 'wp_ajax_nopriv_zeko_rewards_filter_history', array( $this, 'denied' ) );
	}

	/**
	 * Redeem.
	 */
	public function redeem(): void {
		check_ajax_referer( 'zeko_rewards_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-rewards' ) ), 403 );
		}

		$points = isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : 0;
		$result = $this->engine->redeem( $user_id, $points );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success(
			array(
				'message' => $result['message'],
				'points'  => $result['points'],
				'amount'  => $result['amount'],
				'balance' => $result['balance'],
			)
		);
	}

	/**
	 * Redeem item.
	 */
	public function redeem_item(): void {
		check_ajax_referer( 'zeko_rewards_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-rewards' ) ), 403 );
		}

		$item_id = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;
		$result  = $this->engine->redeem_catalog_item( $user_id, $item_id );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success(
			array(
				'message'    => $result['message'],
				'request_id' => $result['request_id'],
				'balance'    => $result['balance'],
			)
		);
	}

	/**
	 * Mark read.
	 */
	public function mark_read(): void {
		check_ajax_referer( 'zeko_rewards_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-rewards' ) ), 403 );
		}

		$this->db->mark_notifications_read( $user_id );
		wp_send_json_success(
			array(
				'count' => 0,
			)
		);
	}

	/**
	 * Filter history.
	 */
	public function filter_history(): void {
		check_ajax_referer( 'zeko_rewards_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-rewards' ) ), 403 );
		}

		$module = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$action = isset( $_POST['action_filter'] ) ? sanitize_key( wp_unslash( $_POST['action_filter'] ) ) : '';
		$page   = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$limit  = 15;
		$offset = ( $page - 1 ) * $limit;

		$events = $this->db->get_events_filtered( $user_id, $module, $action, $limit, $offset );
		$total  = $this->db->count_events_filtered( $user_id, $module, $action );
		$pages  = max( 1, (int) ceil( $total / $limit ) );

		wp_send_json_success(
			array(
				'events' => $events,
				'total'  => $total,
				'pages'  => $pages,
				'page'   => $page,
				'module' => $module,
				'action' => $action,
			)
		);
	}

	/**
	 * Denied.
	 */
	public function denied(): void {
		wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-rewards' ) ), 403 );
	}
}
