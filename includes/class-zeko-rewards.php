<?php
/**
 * Zeko Rewards main class.
 *
 * Owns the module lifecycle: instantiates the DB layer, engine, shortcodes,
 * AJAX handlers, REST endpoints and the admin page, then exposes a small
 * public API for the theme and other modules.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards. */
class Zeko_Rewards {

	/**
	 * Instance.
	 *
	 * @var ?Zeko_Rewards Instance.
	 */
	private static ?Zeko_Rewards $instance = null;

	/**
	 * Db.
	 *
	 * @var ?Zeko_Rewards_DB Db.
	 */
	private ?Zeko_Rewards_DB $db = null;
	/**
	 * Engine.
	 *
	 * @var ?Zeko_Rewards_Engine Engine.
	 */
	private ?Zeko_Rewards_Engine $engine = null;
	/**
	 * Ecosystem.
	 *
	 * @var ?Zeko_Rewards_Ecosystem Ecosystem.
	 */
	private ?Zeko_Rewards_Ecosystem $ecosystem = null;

	/**
	 * Instance.
	 */
	public static function instance(): Zeko_Rewards {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		$this->db     = new Zeko_Rewards_DB();
		$this->engine = new Zeko_Rewards_Engine( $this->db );

		add_action( 'init', array( $this, 'init_components' ), 5 );
	}

	/**
	 * Init components.
	 */
	public function init_components(): void {
		$this->ensure_schema();

		$this->engine->init();

		// Shortcodes.
		add_shortcode( 'zeko_rewards', array( $this, 'shortcode_dashboard' ) );
		add_shortcode( 'zeko_rewards_leaderboard', array( $this, 'shortcode_leaderboard' ) );
		add_shortcode( 'zeko_rewards_badges', array( $this, 'shortcode_badges' ) );
		add_shortcode( 'zeko_rewards_catalog', array( $this, 'shortcode_catalog' ) );
		add_shortcode( 'zeko_rewards_history', array( $this, 'shortcode_history' ) );

		// AJAX + REST.
		new Zeko_Rewards_Ajax( $this->engine, $this->db );
		new Zeko_Rewards_REST( $this->db );

		// Ecosystem integration (nav, admin bar, header bell, dashboard tab).
		$this->ecosystem = new Zeko_Rewards_Ecosystem( $this->db );

		// Assets + admin.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
		if ( is_admin() ) {
			new Zeko_Rewards_Admin( $this->db );
		}

		// Notify theme how many rewards notifications a user has unread.
		add_filter( 'zeko_rewards_unread_count', array( $this, 'unread_count' ), 10, 1 );
	}

	/**
	 * Db.
	 */
	public function get_db(): Zeko_Rewards_DB {
		return $this->db;
	}

	/**
	 * Create/upgrade tables and seed badges when the stored schema version is
	 * behind the code version (idempotent, mirrors the plugins_loaded hook).
	 */
	public function ensure_schema(): void {
		$installed = get_option( 'zeko_rewards_db_version', '0' );
		if ( version_compare( $installed, ZEKO_REWARDS_DB_VERSION, '<' ) ) {
			$this->db->create_tables();
			$this->db->seed_default_badges();
			update_option( 'zeko_rewards_db_version', ZEKO_REWARDS_DB_VERSION );
		}
	}

	/**
	 * Engine.
	 */
	public function get_engine(): Zeko_Rewards_Engine {
		return $this->engine;
	}

	/**
	 * Ecosystem.
	 */
	public function get_ecosystem(): Zeko_Rewards_Ecosystem {
		if ( ! $this->ecosystem ) {
			$this->ecosystem = new Zeko_Rewards_Ecosystem( $this->db );
		}
		return $this->ecosystem;
	}

	/**
	 * Award points programmatically (public API for other modules/theme).
	 *
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 * @param int    $reference_id Reference id.
	 * @param string $reference_type Reference type.
	 * @param string $note Note.
	 * @param int    $multiplier Multiplier.
	 */
	public function award( int $user_id, string $action, int $reference_id = 0, string $reference_type = '', string $note = '', int $multiplier = 1 ): ?int {
		return $this->engine->award( $user_id, $action, $reference_id, $reference_type, $note, $multiplier );
	}

	/**
	 * Unread count.
	 *
	 * @param int $default Default.
	 */
	public function unread_count( int $default = 0 ): int {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $default;
		}
		return $this->db->unread_notifications_count( $user_id );
	}

	// ═══════════════════════════════════════════════════════════════.
	// SHORTCODES.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Shortcode dashboard.
	 */
	public function shortcode_dashboard(): string {
		if ( ! is_user_logged_in() ) {
			return $this->render( 'login' );
		}
		return $this->render( 'dashboard' );
	}

	/**
	 * Shortcode leaderboard.
	 */
	public function shortcode_leaderboard(): string {
		return $this->render( 'leaderboard' );
	}

	/**
	 * Shortcode badges.
	 */
	public function shortcode_badges(): string {
		return $this->render( 'badges' );
	}

	/**
	 * Shortcode catalog.
	 */
	public function shortcode_catalog(): string {
		return $this->render( 'catalog' );
	}

	/**
	 * Shortcode history.
	 */
	public function shortcode_history(): string {
		if ( ! is_user_logged_in() ) {
			return $this->render( 'login' );
		}
		return $this->render( 'history' );
	}

	// ═══════════════════════════════════════════════════════════════.
	// ASSETS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Enqueue public assets.
	 */
	public function enqueue_public_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$has_shortcode = has_shortcode( $post->post_content, 'zeko_rewards' )
			|| has_shortcode( $post->post_content, 'zeko_rewards_leaderboard' )
			|| has_shortcode( $post->post_content, 'zeko_rewards_badges' )
			|| has_shortcode( $post->post_content, 'zeko_rewards_catalog' )
			|| has_shortcode( $post->post_content, 'zeko_rewards_history' );

		if ( ! $has_shortcode ) {
			return;
		}

		wp_enqueue_style( 'zeko-rewards', ZEKO_REWARDS_URL . 'assets/css/zeko-rewards.css', array( 'zeko-core' ), ZEKO_REWARDS_VERSION );
		wp_enqueue_script( 'zeko-rewards', ZEKO_REWARDS_URL . 'assets/js/zeko-rewards.js', array( 'jquery' ), ZEKO_REWARDS_VERSION, true );
		wp_localize_script(
			'zeko-rewards',
			'ZekoRewards',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => esc_url_raw( rest_url( 'zeko-rewards/v1/' ) ),
				'nonce'   => wp_create_nonce( 'zeko_rewards_nonce' ),
				'i18n'    => array(
					'redeeming' => __( 'Redeeming…', 'zeko-rewards' ),
					'copied'    => __( 'Copied!', 'zeko-rewards' ),
					'error'     => __( 'Something went wrong.', 'zeko-rewards' ),
				),
			)
		);
	}

	// ═══════════════════════════════════════════════════════════════.
	// TEMPLATES.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Render a template part (plugin templates take priority, theme can
	 * override via zeko-rewards/{template}.php).
	 *
	 * @param string $template Template.
	 */
	private function render( string $template ): string {
		$theme = locate_template( array( 'zeko-rewards/' . $template . '.php' ) );
		$path  = $theme ? $theme : ZEKO_REWARDS_PLUGIN_PATH . 'templates/' . $template . '.php';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		$rewards = $this;
		$db      = $this->db;
		$engine  = $this->engine;
		$user_id = get_current_user_id();

		ob_start();
		include $path;
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'zeko_rewards' ) ) {
	/**
	 * Zeko rewards.
	 */
	function zeko_rewards(): Zeko_Rewards {
		return Zeko_Rewards::instance();
	}
}
