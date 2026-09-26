<?php
/**
 * Zeko Rewards engine.
 *
 * Listens to every module's do_action hooks (and the theme signup event),
 * converts activity into reward points, unlocks badges and tiers, and runs
 * the expiration cron. This is the cross-module heart of the plugin.
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards_Engine. */
class Zeko_Rewards_Engine {

	/**
	 * Db.
	 *
	 * @var Zeko_Rewards_DB Db.
	 */
	private Zeko_Rewards_DB $db;

	/**
	 * Construct.
	 *
	 * @param Zeko_Rewards_DB $db Db.
	 */
	public function __construct( Zeko_Rewards_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Init.
	 */
	public function init(): void {
		$this->register_event_hooks();

		add_action( 'zeko_rewards_expire', array( $this, 'run_expiration' ) );
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );

		// Backfill expiry columns on installs that upgraded mid-lifecycle.
		add_action( 'init', array( $this, 'ensure_cron' ), 20 );
	}

	/**
	 * Ensure cron.
	 */
	public function ensure_cron(): void {
		if ( ! wp_next_scheduled( 'zeko_rewards_expire' ) ) {
			wp_schedule_event( time(), 'daily', 'zeko_rewards_expire' );
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// EVENT HOOK MAPPING.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Event hooks.
	 */
	private function register_event_hooks(): void {
		// Theme / core.
		add_action( 'user_register', array( $this, 'on_signup' ), 10, 1 );

		// Referral signups are recorded by Zeko Pay on user_register(10);.
		// run at 20 so the wp_zeko_referrals row exists before we read it.
		add_action( 'user_register', array( $this, 'on_referral_signup' ), 20, 1 );

		// Love (both AJAX and REST fire different hooks for the same event).
		add_action( 'zeko_love_new_match', array( $this, 'on_love_match' ), 10, 2 );
		add_action( 'zeko_love_match_created', array( $this, 'on_love_match' ), 10, 3 );
		add_action( 'zeko_love_date_scheduled', array( $this, 'on_love_date' ), 10, 1 );

		// Jobs.
		add_action( 'zeko_jobs_job_posted', array( $this, 'on_job_posted' ), 10, 2 );
		add_action( 'zeko_jobs_job_boosted', array( $this, 'on_job_boosted' ), 10, 2 );

		// Freelance.
		add_action( 'zeko_freelance_project_created', array( $this, 'on_freelance_project' ), 10, 2 );
		add_action( 'zeko_freelance_bid_placed', array( $this, 'on_freelance_bid_placed' ), 10, 3 );
		add_action( 'zeko_freelance_bid_awarded', array( $this, 'on_freelance_bid_awarded' ), 10, 4 );
		add_action( 'zeko_freelance_milestone_approved', array( $this, 'on_freelance_milestone_approved' ), 10, 4 );

		// Learn.
		add_action( 'zeko_learn_enrollment_confirmed', array( $this, 'on_course_enrolled' ), 10, 2 );
		add_action( 'zeko_learn_course_completed', array( $this, 'on_course_completed' ), 10, 2 );

		// Mentor.
		add_action( 'zeko_mentor_program_enrolled', array( $this, 'on_mentor_program' ), 10, 2 );
		add_action( 'zeko_mentor_match_accepted', array( $this, 'on_mentor_match' ), 10, 2 );
		add_action( 'zeko_mentor_application_verified', array( $this, 'on_mentor_verified' ), 10, 2 );
		add_action( 'zeko_mentor_review_posted', array( $this, 'on_mentor_review' ), 10, 2 );

		// Shop.
		add_action( 'zeko_shop_order_completed', array( $this, 'on_shop_order' ), 10, 4 );

		// QA.
		add_action( 'zeko_qa_activity_logged', array( $this, 'on_qa_activity' ), 10, 4 );
		add_action( 'zeko_qa_answer_accepted', array( $this, 'on_qa_answer_accepted' ), 10, 3 );

		// AI.
		add_action( 'zeko_ai_conversation_replied', array( $this, 'on_ai_conversation' ), 10, 2 );
	}

	/**
	 * On signup.
	 *
	 * @param mixed $user_id User id.
	 */
	public function on_signup( $user_id ): void {
		$this->award( (int) $user_id, 'signup' );
	}

	/**
	 * Award referral points when a new user signs up through Zeko Pay's
	 * referral flow: the referrer earns points and the new user gets a bonus.
	 * Relies on the wp_zeko_referrals signup row created by Zeko Pay at
	 * user_register(10); runs at priority 20.
	 *
	 * @param mixed $user_id User id.
	 */
	public function on_referral_signup( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'Zeko_Pay_Referrals' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_referrals';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$referral = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, referrer_id FROM {$table} WHERE referee_id = %d AND type = 'signup' ORDER BY id DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( ! $referral || empty( $referral['referrer_id'] ) ) {
			return;
		}

		$referral_id = (int) $referral['id'];
		$referrer_id = (int) $referral['referrer_id'];

		// Referrer earns for each successful referral (unique per referral row).
		$this->award( $referrer_id, 'referral_signup', $referral_id, 'referral' );

		// New user gets a one-time bonus for signing up via a friend.
		$this->award( $user_id, 'referral_bonus', $referral_id, 'referral' );
	}

	/**
	 * On love match.
	 *
	 * @param mixed $match_id Match id.
	 * @param mixed $user_id User id.
	 */
	public function on_love_match( $match_id, $user_id ): void {
		$this->award( (int) $user_id, 'love_match', $match_id, 'match' );
	}

	/**
	 * On love date.
	 *
	 * @param mixed $schedule_id Schedule id.
	 */
	public function on_love_date( $schedule_id ): void {
		$user_id = 0;
		if ( function_exists( 'zeko_love' ) && zeko_love() && method_exists( zeko_love()->get_db(), 'get_date' ) ) {
			$date    = zeko_love()->get_db()->get_date( (int) $schedule_id );
			$user_id = $date ? (int) ( $date['created_by'] ?? $date['user1_id'] ?? 0 ) : 0;
		}
		if ( $user_id > 0 ) {
			$this->award( $user_id, 'love_date', (int) $schedule_id, 'date' );
		}
	}

	/**
	 * On job posted.
	 *
	 * @param mixed $job_id Job id.
	 * @param mixed $user_id User id.
	 */
	public function on_job_posted( $job_id, $user_id ): void {
		$this->award( (int) $user_id, 'job_posted', (int) $job_id, 'job' );
	}

	/**
	 * On job boosted.
	 *
	 * @param mixed $job_id Job id.
	 * @param mixed $user_id User id.
	 */
	public function on_job_boosted( $job_id, $user_id ): void {
		$this->award( (int) $user_id, 'job_boosted', (int) $job_id, 'job' );
	}

	/**
	 * On freelance project.
	 *
	 * @param mixed $project_id Project id.
	 * @param mixed $user_id User id.
	 */
	public function on_freelance_project( $project_id, $user_id ): void {
		$this->award( (int) $user_id, 'freelance_project_posted', (int) $project_id, 'project' );
	}

	/**
	 * On freelance bid placed.
	 *
	 * @param mixed $bid_id Bid id.
	 * @param mixed $project_id Project id.
	 * @param mixed $user_id User id.
	 */
	public function on_freelance_bid_placed( $bid_id, $project_id, $user_id ): void {
		$this->award( (int) $user_id, 'freelance_bid_placed', (int) $bid_id, 'bid' );
	}

	/**
	 * On freelance bid awarded.
	 *
	 * @param mixed $bid_id Bid id.
	 * @param mixed $contract_id Contract id.
	 * @param mixed $project_id Project id.
	 * @param mixed $user_id User id.
	 */
	public function on_freelance_bid_awarded( $bid_id, $contract_id, $project_id, $user_id ): void {
		$this->award( (int) $user_id, 'freelance_contract_won', (int) $contract_id, 'contract' );
	}

	/**
	 * Freelancer earns milestone points when the client approves the work.
	 * The 4th hook arg carries the freelancer user id; falls back to the
	 * contract freelancer via the freelance DB when it is absent.
	 *
	 * @param mixed     $milestone_id Milestone id.
	 * @param mixed     $contract_id Contract id.
	 * @param mixed     $release_id Release id.
	 * @param int|float $user_id User id.
	 */
	public function on_freelance_milestone_approved( $milestone_id, $contract_id, $release_id, $user_id = 0 ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 && function_exists( 'zeko_freelance' ) && zeko_freelance() && method_exists( zeko_freelance()->get_db(), 'get_contract' ) ) {
			$contract = zeko_freelance()->get_db()->get_contract( (int) $contract_id );
			$user_id  = $contract ? (int) $contract->freelancer_id : 0;
		}
		if ( $user_id > 0 ) {
			$this->award( $user_id, 'freelance_milestone_approved', (int) $milestone_id, 'milestone' );
		}
	}

	/**
	 * On course enrolled.
	 *
	 * @param mixed $user_id User id.
	 * @param mixed $course_id Course id.
	 */
	public function on_course_enrolled( $user_id, $course_id ): void {
		$this->award( (int) $user_id, 'course_enrolled', (int) $course_id, 'course' );
	}

	/**
	 * On course completed.
	 *
	 * @param mixed $user_id User id.
	 * @param mixed $course_id Course id.
	 */
	public function on_course_completed( $user_id, $course_id ): void {
		$this->award( (int) $user_id, 'course_completed', (int) $course_id, 'course' );
	}

	/**
	 * On mentor program.
	 *
	 * @param mixed $program_id Program id.
	 * @param mixed $user_id User id.
	 */
	public function on_mentor_program( $program_id, $user_id ): void {
		$this->award( (int) $user_id, 'mentor_program', (int) $program_id, 'program' );
	}

	/**
	 * On mentor match.
	 *
	 * @param mixed $match_id Match id.
	 * @param mixed $user_id User id.
	 */
	public function on_mentor_match( $match_id, $user_id ): void {
		$this->award( (int) $user_id, 'mentor_match', (int) $match_id, 'match' );
	}

	/**
	 * On mentor verified.
	 *
	 * @param mixed $user_id User id.
	 * @param mixed $status Status.
	 */
	public function on_mentor_verified( $user_id, $status ): void {
		if ( in_array( (string) $status, array( 'verified', 'approved', 'accepted' ), true ) ) {
			$this->award( (int) $user_id, 'mentor_verified' );
		}
	}

	/**
	 * On mentor review.
	 *
	 * @param mixed $session_id Session id.
	 * @param mixed $reviewer_id Reviewer id.
	 */
	public function on_mentor_review( $session_id, $reviewer_id ): void {
		$this->award( (int) $reviewer_id, 'mentor_review', (int) $session_id, 'session' );
	}

	/**
	 * On shop order.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $user_id User id.
	 */
	public function on_shop_order( $order_id, $user_id ): void {
		$this->award( (int) $user_id, 'shop_order', (int) $order_id, 'order' );
	}

	/**
	 * QA earning events arrive through the shared `zeko_qa_activity_logged`
	 * hook. Only the actions configured below earn points; the actor is the
	 * user who performed the action.
	 *
	 * @param mixed $actor_id Actor id.
	 * @param mixed $action Action.
	 * @param mixed $object_id Object id.
	 * @param mixed $object_type Object type.
	 */
	public function on_qa_activity( $actor_id, $action, $object_id, $object_type ): void {
		$map = array(
			'asked_question'    => 'qa_question',
			'answered_question' => 'qa_answer',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}
		$this->award( (int) $actor_id, $map[ $action ], (int) $object_id, (string) $object_type );
	}

	/**
	 * The answer author earns a bonus when their answer is accepted. Fired by
	 * the QA ajax handler with the answer author as the first argument (the
	 * shared activity hook carries the accepter instead).
	 *
	 * @param mixed $answer_author_id Answer author id.
	 * @param mixed $answer_id Answer id.
	 * @param mixed $question_id Question id.
	 */
	public function on_qa_answer_accepted( $answer_author_id, $answer_id, $question_id ): void {
		unset( $question_id );
		$this->award( (int) $answer_author_id, 'qa_answer_accepted', (int) $answer_id, 'answer' );
	}

	/**
	 * One award per AI conversation (reference id dedupes repeated turns
	 * within the same conversation).
	 *
	 * @param mixed $user_id User id.
	 * @param mixed $conversation_id Conversation id.
	 */
	public function on_ai_conversation( $user_id, $conversation_id ): void {
		$this->award( (int) $user_id, 'ai_conversation', (int) $conversation_id, 'conversation' );
	}

	// ═══════════════════════════════════════════════════════════════.
	// AWARD.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Award points for an activity, idempotently.
	 *
	 * @return int|null Event id, or null when skipped.
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 * @param int    $reference_id Reference id.
	 * @param string $reference_type Reference type.
	 * @param string $note Note.
	 * @param int    $multiplier Multiplier.
	 */
	public function award( int $user_id, string $action, int $reference_id = 0, string $reference_type = '', string $note = '', int $multiplier = 1 ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}

		$config = zeko_rewards_get_config();
		if ( ! isset( $config[ $action ] ) ) {
			return null;
		}

		$cfg    = $config[ $action ];
		$points = (int) ( $cfg['points'] ?? 0 ) * max( 1, $multiplier );

		if ( $points <= 0 ) {
			return null;
		}

		// An active daily streak multiplies the base award. Admins or other.
		// modules can also inject a global event multiplier via the filter.
		$streak_multiplier = $this->streak_multiplier( $user_id );
		if ( $streak_multiplier > 1 ) {
			$points = (int) round( $points * $streak_multiplier );
		}

		if ( $points <= 0 ) {
			return null;
		}

		// Idempotency: 'once' actions can only ever be awarded once; the rest.
		// are de-duplicated by their reference id. The check-then-insert pair.
		// runs under a per-(user,action,reference) advisory lock so concurrent.
		// double-submissions cannot both pass the check and double the award.
		// (SELECT-then-INSERT is racy; a UNIQUE key on (user, action,.
		// reference_id) can't be added because internal repeat events — streak.
		// bonuses, redemptions, catalog spends — legitimately share a.
		// reference_id of 0 or repeat the same item).
		global $wpdb;
		$lock_key = 'zeko_rewards_award_' . (int) $user_id . '_' . $action . '_' . (int) $reference_id;
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key ) );

		try {
			if ( ! empty( $cfg['once'] ) ) {
				if ( $this->db->user_has_action( $user_id, $action ) ) {
					return null;
				}
			} elseif ( $reference_id > 0 && $this->db->event_exists( $user_id, $action, $reference_id ) ) {
				return null;
			}

			// Earning activity also advances the user's daily streak.
			$this->touch_streak( $user_id );

			$event_id = $this->db->log_event(
				$user_id,
				$points,
				$action,
				$cfg['module'] ?? 'core',
				$reference_id,
				$reference_type,
				$note ? $note : ( $cfg['label'] ?? $action ),
				'activity',
				$this->expiry_for()
			);

			$this->notify_user(
				$user_id,
				'reward_earned',
				/* translators: 1: number of points. 2: action label */
				sprintf( __( 'You earned %1$d points: %2$s', 'zeko-rewards' ), $points, $cfg['label'] ?? $action ),
				'event-' . $event_id
			);

			$this->log_activity(
				$user_id,
				'reward_points',
				/* translators: 1: number of points. 2: action label */
				sprintf( __( '+%1$d points: %2$s', 'zeko-rewards' ), $points, $cfg['label'] ?? $action ),
				$event_id
			);

			$this->evaluate_badges( $user_id );
			$this->evaluate_tier( $user_id );

			do_action( 'zeko_rewards_points_earned', $user_id, $points, $action, $event_id );

			return $event_id;
		} finally {
			if ( $got_lock ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
			}
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// BADGES.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Unlock any badges whose criteria the user now meets.
	 *
	 * @return array Badge rows unlocked this call.
	 * @param int $user_id User id.
	 */
	public function evaluate_badges( int $user_id ): array {
		$badges   = $this->db->get_badges( true );
		$lifetime = $this->db->user_lifetime_points( $user_id );
		$unlocked = array();

		foreach ( $badges as $badge ) {
			if ( $this->db->has_badge( $user_id, (int) $badge['badge_id'] ) ) {
				continue;
			}

			$met = false;
			switch ( $badge['criteria_type'] ) {
				case 'points_total':
					$met = $lifetime >= (int) $badge['criteria_value'];
					break;
				case 'module_points':
					$met = $this->db->module_points( $user_id, (string) $badge['criteria_module'] ) >= (int) $badge['criteria_value'];
					break;
				case 'action_count':
					$met = $this->db->action_count( $user_id, (string) $badge['criteria_action'] ) >= (int) $badge['criteria_value'];
					break;
			}

			if ( ! $met ) {
				continue;
			}

			$this->db->award_badge( $user_id, (int) $badge['badge_id'] );
			$unlocked[] = $badge;

			$this->notify_user(
				$user_id,
				'badge_unlocked',
				/* translators: %s: badge name */
				sprintf( __( 'Badge unlocked: %s', 'zeko-rewards' ), $badge['name'] ),
				'badge-' . $badge['slug']
			);

			// Badge bonus points (logged directly to skip the 'once' guard).
			if ( (int) $badge['points'] > 0 ) {
				$event_id = $this->db->log_event(
					$user_id,
					(int) $badge['points'],
					'badge_unlock',
					'core',
					(int) $badge['badge_id'],
					'badge',
					/* translators: %s: badge name */
					sprintf( __( 'Badge unlocked: %s', 'zeko-rewards' ), $badge['name'] ),
					'badge',
					$this->expiry_for()
				);
				$this->log_activity(
					$user_id,
					'reward_badge',
					/* translators: 1: number of points. 2: badge name */
					sprintf( __( '+%1$d points for the %2$s badge', 'zeko-rewards' ), (int) $badge['points'], $badge['name'] ),
					$event_id
				);
			}
		}

		if ( $unlocked ) {
			$this->evaluate_tier( $user_id );
		}

		return $unlocked;
	}

	// ═══════════════════════════════════════════════════════════════.
	// TIERS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Recalculate the user's tier from lifetime points; notify on upgrade.
	 * The tier is allowed to fall when points expire (it must not stick at
	 * diamond forever), but the "you reached X tier" notification is only sent
	 * on a genuine upgrade — never for a downgrade or a sideways change.
	 *
	 * @param int $user_id User id.
	 */
	public function evaluate_tier( int $user_id ): string {
		$lifetime = $this->db->user_lifetime_points( $user_id );
		$tiers    = zeko_rewards_get_tiers();

		$tier  = 'bronze';
		$ranks = array();
		$i     = 0;
		foreach ( $tiers as $key => $t ) {
			$ranks[ $key ] = $i++;
			if ( $lifetime >= (int) $t['threshold'] ) {
				$tier = $key;
			}
		}

		$old = get_user_meta( $user_id, 'zeko_rewards_tier', true );
		if ( $old === $tier ) {
			return $tier;
		}

		update_user_meta( $user_id, 'zeko_rewards_tier', $tier );

		// Only announce genuine upgrades. Skip when the tier is first set (no.
		// prior tier yet) and when the change is a downgrade or sideways move.
		$old_rank = isset( $old, $ranks[ $old ] ) ? (int) $ranks[ $old ] : -1;
		if ( $old_rank >= 0 && (int) $ranks[ $tier ] > $old_rank ) {
			$label = $tiers[ $tier ]['label'] ?? $tier;
			$this->notify_user(
				$user_id,
				'tier_upgraded',
				/* translators: %s: tier label */
				sprintf( __( 'You reached the %s tier!', 'zeko-rewards' ), $label ),
				'tier-' . $tier
			);
		}

		return $tier;
	}

	// ═══════════════════════════════════════════════════════════════.
	// REDEMPTION.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Redeem available points for wallet credit.
	 *
	 * @return array{success:bool,message:string,points?:int,amount?:float,balance?:int}
	 * @param int $user_id User id.
	 * @param int $points Points.
	 */
	public function redeem( int $user_id, int $points ): array {
		if ( $user_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Please log in.', 'zeko-rewards' ),
			);
		}

		$settings = zeko_rewards_get_settings();
		if ( empty( $settings['enable_redemption'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Redemption is disabled.', 'zeko-rewards' ),
			);
		}

		$rate   = max( 1, (int) $settings['points_per_dollar'] );
		$min    = max( 1, (int) $settings['min_redeem_points'] );
		$points = absint( $points );

		if ( $points < $min ) {
			return array(
				'success' => false,
				/* translators: %d: minimum points required */
				'message' => sprintf( __( 'Minimum redemption is %d points.', 'zeko-rewards' ), $min ),
			);
		}

		// Serialize redemptions per user so two concurrent requests can't both.
		// pass the balance check and overspend the same points.
		global $wpdb;
		$lock_key = 'zeko_rewards_redeem_' . $user_id;
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key ) );

		if ( ! $got_lock ) {
			return array(
				'success' => false,
				'message' => __( 'Redemption is busy. Please try again.', 'zeko-rewards' ),
			);
		}

		try {
			$balance = $this->db->user_points( $user_id );
			if ( $points > $balance ) {
				return array(
					'success' => false,
					'message' => __( 'You do not have enough points.', 'zeko-rewards' ),
				);
			}

			$amount = floor( $points / $rate * 100 ) / 100;
			if ( $amount <= 0 ) {
				return array(
					'success' => false,
					'message' => __( 'Not enough points for a redeemable amount.', 'zeko-rewards' ),
				);
			}

			// Spend the points first (audit trail). The wallet is credited only.
			// after the deduction is recorded, so a failed credit never leaves.
			// free money behind — the points are rolled back in that case.
			$event_id = $this->db->log_event(
				$user_id,
				-$points,
				'redeem',
				'core',
				0,
				'redeem',
				/* translators: 1: number of points. 2: wallet credit amount */
				sprintf( __( 'Redeemed %1$d points for %2$s wallet credit', 'zeko-rewards' ), $points, number_format( $amount, 2 ) ),
				'redeem',
				null
			);

			if ( $event_id <= 0 ) {
				return array(
					'success' => false,
					'message' => __( 'Could not record the redemption.', 'zeko-rewards' ),
				);
			}

			$credit = Zeko_Rewards_Pay::credit_redeemed_points( $user_id, $amount, $points );
			if ( empty( $credit['success'] ) ) {
				// Reverse the point deduction so the user isn't charged twice.
				$this->db->log_event(
					$user_id,
					$points,
					'redeem_rollback',
					'core',
					$event_id,
					'redeem',
					__( 'Restored points after a failed wallet redemption', 'zeko-rewards' ),
					'redeem',
					null
				);

				return array(
					'success' => false,
					'message' => $credit['message'],
				);
			}

			$this->db->log_redemption( $user_id, $points, $amount, $rate, $credit['reference_id'] ?? ( 'redemption-' . $event_id ) );

			$this->notify_user(
				$user_id,
				'reward_redeemed',
				/* translators: 1: number of points. 2: wallet credit amount */
				sprintf( __( 'You redeemed %1$d points for %2$s wallet credit', 'zeko-rewards' ), $points, number_format( $amount, 2 ) ),
				'redemption-' . $event_id
			);

			$this->log_activity(
				$user_id,
				'reward_redeemed',
				/* translators: 1: number of points. 2: reward label or amount */
				sprintf( __( 'Redeemed %1$d points for %2$s', 'zeko-rewards' ), $points, number_format( $amount, 2 ) ),
				$event_id
			);

			do_action( 'zeko_rewards_points_redeemed', $user_id, $points, $amount );

			return array(
				'success' => true,
				'message' => __( 'Points redeemed to your wallet.', 'zeko-rewards' ),
				'points'  => $points,
				'amount'  => $amount,
				'balance' => $this->db->user_points( $user_id ),
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// STREAKS & MULTIPLIERS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * On login.
	 *
	 * @param mixed $user_login User login.
	 * @param mixed $user User.
	 */
	public function on_login( $user_login, $user ): void {
		if ( $user && isset( $user->ID ) ) {
			$this->touch_streak( (int) $user->ID );
		}
	}

	/**
	 * Advance the user's daily earning streak. A streak continues while the
	 * user earns (or logs in) on consecutive UTC days and resets otherwise.
	 * A configurable milestone (e.g. every 7 days) pays a bonus.
	 *
	 * @return array{streak:int,bonus_awarded:bool}
	 * @param int $user_id User id.
	 */
	public function touch_streak( int $user_id ): array {
		$result = array(
			'streak'        => 0,
			'bonus_awarded' => false,
		);

		if ( $user_id <= 0 ) {
			return $result;
		}

		$settings = zeko_rewards_get_settings();
		if ( empty( $settings['streak_enabled'] ) ) {
			return $result;
		}

		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$last      = (string) get_user_meta( $user_id, 'zeko_rewards_streak_last', true );
		$streak    = (int) get_user_meta( $user_id, 'zeko_rewards_streak', true );

		if ( $last === $today ) {
			$result['streak'] = $streak;
			return $result;
		}

		$streak = ( $last === $yesterday ) ? $streak + 1 : 1;

		update_user_meta( $user_id, 'zeko_rewards_streak', $streak );
		update_user_meta( $user_id, 'zeko_rewards_streak_last', $today );

		$every = max( 1, (int) $settings['streak_bonus_every'] );
		$bonus = (int) $settings['streak_bonus'];

		if ( 0 === $streak % $every && $bonus > 0 ) {
			$this->award_streak_bonus( $user_id, $streak, $bonus );
			$result['bonus_awarded'] = true;
		}

		$result['streak'] = $streak;
		return $result;
	}

	/**
	 * Current streak.
	 *
	 * @param int $user_id User id.
	 */
	public function current_streak( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$last      = (string) get_user_meta( $user_id, 'zeko_rewards_streak_last', true );
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

		// A stale streak is not "current" until refreshed; report 0 so the.
		// multiplier does not apply to a broken chain.
		if ( $last !== $today && $last !== $yesterday ) {
			return 0;
		}
		return (int) get_user_meta( $user_id, 'zeko_rewards_streak', true );
	}

	/**
	 * Active-streak multiplier (1 when disabled or below the threshold).
	 *
	 * @param int $user_id User id.
	 */
	public function streak_multiplier( int $user_id ): int {
		$settings = zeko_rewards_get_settings();
		$m        = max( 1, (int) $settings['streak_multiplier'] );

		if ( $m <= 1 || empty( $settings['streak_enabled'] ) ) {
			return 1;
		}

		$threshold = max( 1, (int) $settings['streak_multiplier_days'] );
		if ( $this->current_streak( $user_id ) < $threshold ) {
			return 1;
		}

		return max( 1, (int) apply_filters( 'zeko_rewards_multiplier', $m, $user_id ) );
	}

	/**
	 * Award streak bonus.
	 *
	 * @param int $user_id User id.
	 * @param int $streak Streak.
	 * @param int $bonus Bonus.
	 */
	private function award_streak_bonus( int $user_id, int $streak, int $bonus ): void {
		$event_id = $this->db->log_event(
			$user_id,
			$bonus,
			'streak_milestone',
			'core',
			0,
			'streak',
			/* translators: %d: streak day number */
			sprintf( __( 'Day %d streak bonus', 'zeko-rewards' ), $streak ),
			'streak',
			$this->expiry_for()
		);

		$this->notify_user(
			$user_id,
			'streak_bonus',
			/* translators: 1: bonus points. 2: streak length in days */
			sprintf( __( '%1$d-point bonus for your %2$d-day streak!', 'zeko-rewards' ), $bonus, $streak ),
			'streak-' . $streak
		);

		$this->log_activity(
			$user_id,
			'reward_streak',
			/* translators: 1: bonus points. 2: streak length in days */
			sprintf( __( '+%1$d points: %2$d-day streak', 'zeko-rewards' ), $bonus, $streak ),
			$event_id
		);

		$this->evaluate_badges( $user_id );
		$this->evaluate_tier( $user_id );

		do_action( 'zeko_rewards_points_earned', $user_id, $bonus, 'streak_milestone', $event_id );
	}

	// ═══════════════════════════════════════════════════════════════.
	// REWARD CATALOG.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Redeem points for a catalog reward. Creates a pending request that an
	 * admin fulfils manually; points are spent immediately (audit trail).
	 *
	 * @return array{success:bool,message:string,request_id?:int,balance?:int}
	 * @param int $user_id User id.
	 * @param int $item_id Item id.
	 */
	public function redeem_catalog_item( int $user_id, int $item_id ): array {
		if ( $user_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Please log in.', 'zeko-rewards' ),
			);
		}

		$item = $this->db->get_catalog_item( $item_id );
		if ( ! $item || empty( $item['is_active'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'That reward is no longer available.', 'zeko-rewards' ),
			);
		}

		$cost    = (int) $item['points_cost'];
		$balance = $this->db->user_points( $user_id );
		if ( $balance < $cost ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have enough points.', 'zeko-rewards' ),
			);
		}

		// Serialize redemptions per item so the last unit is never oversold.
		global $wpdb;
		$lock_name = 'zeko_rewards_catalog_' . (int) $item_id;
		$wpdb->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

		try {
			// Re-read stock under the lock for an authoritative check.
			$item  = $this->db->get_catalog_item( $item_id );
			$stock = $item ? (int) $item['stock'] : 0;

			if ( ! $item || empty( $item['is_active'] ) || ( $stock >= 0 && $stock < 1 ) ) {
				return array(
					'success' => false,
					'message' => __( 'This reward is out of stock.', 'zeko-rewards' ),
				);
			}

			// Spend the points (negative event = audit trail).
			$event_id = $this->db->log_event(
				$user_id,
				-$cost,
				'catalog_redeem',
				'core',
				$item_id,
				'catalog',
				/* translators: 1: reward name. 2: number of points */
				sprintf( __( 'Redeemed %1$s (%2$d points)', 'zeko-rewards' ), $item['name'], $cost ),
				'redeem',
				null
			);

			// Atomically reserve a unit; unlimited stock (-1) is never decremented.
			$reserved = ( $stock < 0 ) || $this->db->reserve_catalog_stock( $item_id );
			if ( ! $reserved ) {
				// The last unit drained before our spend landed — restore points.
				$this->db->log_event(
					$user_id,
					$cost,
					'catalog_oversold',
					'core',
					$item_id,
					'catalog',
					/* translators: %s: reward name */
					sprintf( __( 'Stock adjusted: %s sold out before your redemption completed.', 'zeko-rewards' ), $item['name'] ),
					'refund',
					$this->expiry_for()
				);
				return array(
					'success' => false,
					'message' => __( 'This reward sold out while you were redeeming.', 'zeko-rewards' ),
				);
			}

			$request_id = $this->db->log_catalog_redemption( $user_id, $item_id, $cost );

			$this->notify_user(
				$user_id,
				'catalog_redeemed',
				/* translators: %s: reward name */
				sprintf( __( 'Reward requested: %s. We will contact you to arrange delivery.', 'zeko-rewards' ), $item['name'] ),
				'catalog-' . $request_id
			);

			$this->log_activity(
				$user_id,
				'reward_redeemed',
				/* translators: 1: number of points. 2: reward label or amount */
				sprintf( __( 'Redeemed %1$d points for %2$s', 'zeko-rewards' ), $cost, $item['name'] ),
				$event_id
			);

			do_action( 'zeko_rewards_catalog_redeemed', $user_id, $item_id, $request_id );

			return array(
				'success'    => true,
				'message'    => __( 'Reward requested! We will contact you to arrange delivery.', 'zeko-rewards' ),
				'request_id' => $request_id,
				'balance'    => $this->db->user_points( $user_id ),
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Cancel a pending catalog redemption and refund the points.
	 *
	 * @return array{success:bool,message:string}
	 * @param int    $redemption_id Redemption id.
	 * @param string $note Note.
	 */
	public function cancel_catalog_redemption( int $redemption_id, string $note = '' ): array {
		$redemption = $this->db->get_catalog_redemption( $redemption_id );
		if ( ! $redemption ) {
			return array(
				'success' => false,
				'message' => __( 'Redemption request not found.', 'zeko-rewards' ),
			);
		}
		if ( 'pending' !== $redemption['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Only pending requests can be cancelled.', 'zeko-rewards' ),
			);
		}

		$user_id   = (int) $redemption['user_id'];
		$points    = (int) $redemption['points'];
		$item_id   = (int) $redemption['item_id'];
		$item      = $this->db->get_catalog_item( $item_id );
		$item_name = $item ? $item['name'] : '';

		$this->db->update_catalog_redemption_status( $redemption_id, 'cancelled', $note );

		$event_id = $this->db->log_event(
			$user_id,
			$points,
			'catalog_refund',
			'core',
			$item_id,
			'catalog',
			/* translators: 1: reward name. 2: number of points */
			sprintf( __( 'Refund: %1$s (%2$d points)', 'zeko-rewards' ), $item_name, $points ),
			'refund',
			$this->expiry_for()
		);

		if ( $item && (int) $item['stock'] >= 0 ) {
			$this->db->restore_catalog_stock( $item_id );
		}

		$this->notify_user(
			$user_id,
			'catalog_refunded',
			/* translators: 1: number of points. 2: reward name */
			sprintf( __( 'Your %1$d points for %2$s were refunded.', 'zeko-rewards' ), $points, $item_name ? $item_name : __( 'a reward', 'zeko-rewards' ) ),
			'catalog-refund-' . $redemption_id
		);

		$this->log_activity(
			$user_id,
			'reward_refunded',
			/* translators: 1: number of points. 2: reward name */
			sprintf( __( '+%1$d points refunded for %2$s', 'zeko-rewards' ), $points, $item_name ),
			$event_id
		);

		return array(
			'success' => true,
			'message' => __( 'Request cancelled and points refunded.', 'zeko-rewards' ),
		);
	}

	// ═══════════════════════════════════════════════════════════════.
	// MANUAL ADJUSTMENTS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Admin-awarded points adjustment with a note. The events ledger is the
	 * audit trail (action = manual_adjust).
	 *
	 * @return int|null Event id, or null when rejected.
	 * @param int    $user_id Target user.
	 * @param int    $points Positive to award, negative to deduct.
	 * @param string $note Reason shown to the user and in the ledger.
	 * @param int    $actor_id Admin who made the change.
	 */
	public function adjust_points( int $user_id, int $points, string $note = '', int $actor_id = 0 ): ?int {
		if ( $user_id <= 0 || 0 === $points ) {
			return null;
		}

		$note = trim( $note );
		if ( '' === $note ) {
			$note = $points > 0 ? __( 'Manual award', 'zeko-rewards' ) : __( 'Manual deduction', 'zeko-rewards' );
		}
		if ( $actor_id > 0 ) {
			$actor = get_userdata( $actor_id );
			$note .= ' (' . ( $actor ? $actor->display_name : '#' . $actor_id ) . ')';
		}

		$event_id = $this->db->log_event(
			$user_id,
			$points,
			'manual_adjust',
			'core',
			0,
			'manual',
			$note,
			'admin',
			$points > 0 ? $this->expiry_for() : null
		);

		$this->notify_user(
			$user_id,
			'manual_adjust',
			sprintf(
				// translators: %1$s signed point amount, %2$s reason.
				__( '%1$s points: %2$s', 'zeko-rewards' ),
				( $points > 0 ? '+' : '' ) . number_format_i18n( $points ),
				$note
			),
			'manual-' . $event_id
		);

		$this->log_activity(
			$user_id,
			'reward_manual',
			sprintf(
				// translators: %1$s signed point amount, %2$s reason.
				__( '%1$s points: %2$s', 'zeko-rewards' ),
				( $points > 0 ? '+' : '' ) . number_format_i18n( $points ),
				$note
			),
			$event_id
		);

		$this->evaluate_badges( $user_id );
		$this->evaluate_tier( $user_id );

		do_action( 'zeko_rewards_manual_adjust', $user_id, $points, $note, $event_id );

		return $event_id;
	}

	// ═══════════════════════════════════════════════════════════════.
	// EXPIRATION.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Run expiration.
	 */
	public function run_expiration(): void {
		$this->db->prune_read_notifications( 500 );

		$settings = zeko_rewards_get_settings();
		if ( (int) $settings['expire_days'] <= 0 ) {
			return;
		}

		$events   = $this->db->recently_expired_events();
		$notified = array();

		foreach ( $events as $event ) {
			$user_id = (int) $event['user_id'];
			if ( $event['points'] <= 0 || $user_id <= 0 ) {
				continue;
			}

			$key              = $user_id . '-' . $event['action'];
			$notified[ $key ] = ( $notified[ $key ] ?? 0 ) + (int) $event['points'];
		}

		foreach ( $notified as $key => $total_points ) {
			list( $user_id, $action ) = explode( '-', $key, 2 );
			$user_id                  = (int) $user_id;

			$ref = 'expire-' . $action . '-' . gmdate( 'Ymd' );
			if ( $this->db->has_notification( $user_id, 'points_expired', $ref ) ) {
				continue;
			}

			$this->db->add_notification(
				$user_id,
				'points_expired',
				/* translators: 1: number of points. 2: module label */
				sprintf( __( '%1$d %2$s points expired', 'zeko-rewards' ), $total_points, $action ),
				$ref
			);
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// HELPERS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Expiry datetime for newly earned points (null when disabled).
	 */
	private function expiry_for(): ?string {
		$settings = zeko_rewards_get_settings();
		$days     = (int) $settings['expire_days'];
		if ( $days <= 0 ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Notify user.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param string $message Message.
	 * @param string $reference Reference.
	 */
	private function notify_user( int $user_id, string $type, string $message, string $reference ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$this->db->add_notification( $user_id, $type, $message, $reference );

		do_action( 'zeko_rewards_notification', $user_id, $type, $message, $reference );
	}

	/**
	 * Log activity.
	 *
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 * @param string $message Message.
	 * @param int    $item_id Item id.
	 */
	private function log_activity( int $user_id, string $action, string $message, int $item_id = 0 ): void {
		if ( ! class_exists( 'Zeko_Core_Activity' ) ) {
			return;
		}
		Zeko_Core_Activity::get_instance()->log( $user_id, $action, $message, $item_id, array( 'source' => 'zeko_rewards' ) );
	}
}
