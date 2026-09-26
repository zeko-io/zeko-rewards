<?php
/**
 * Zeko Rewards wallet & redemption bridge to Zeko Pay.
 *
 * A small, safe wrapper around the Zeko Pay ledger so the rewards module can
 * credit redeemed points to a user's wallet without touching the ledger
 * directly (mirrors Zeko_Love_Pay).
 *
 * @package Zeko_Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Rewards_Pay. */
class Zeko_Rewards_Pay {

	/**
	 * Whether the Zeko Pay wallet system is available.
	 */
	public static function active(): bool {
		return class_exists( 'Zeko_Pay_SDK' ) && class_exists( 'Zeko_Pay_Ledger' );
	}

	/**
	 * Current wallet balance for a user (formatted amount string).
	 *
	 * @param int $user_id User id.
	 */
	public static function balance( int $user_id ): string {
		if ( ! self::active() ) {
			return '0.00';
		}
		$sdk = new Zeko_Pay_SDK();
		return $sdk->get_balance( $user_id );
	}

	/**
	 * Credit a user's wallet from a rewards redemption.
	 *
	 * @return array{success:bool,message:string}
	 * @param int   $user_id Recipient user id.
	 * @param float $amount Amount to credit.
	 * @param int   $points Points spent (ledger metadata).
	 */
	public static function credit_redeemed_points( int $user_id, float $amount, int $points ): array {
		if ( ! self::active() ) {
			return array(
				'success' => false,
				'message' => __( 'Wallet system is not available.', 'zeko-rewards' ),
			);
		}
		if ( $user_id <= 0 || $amount <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid redemption.', 'zeko-rewards' ),
			);
		}

		$ledger    = Zeko_Pay_Ledger::instance();
		$wallet_id = $ledger->get_or_create_wallet( $user_id );

		if ( $wallet_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Wallet could not be created.', 'zeko-rewards' ),
			);
		}

		// Always give the wallet a real number so future creations don't.
		// collide with the unique account_number column. The ledger mints a.
		// number at creation already; this backfills any legacy bare rows.
		if ( class_exists( 'Zeko_Pay_Accounts' ) ) {
			Zeko_Pay_Accounts::instance()->ensure_account_number( $wallet_id );
		}

		$ref_id = 'rewards-' . $user_id . '-' . time() . '-' . wp_generate_password( 6, false );

		$result = $ledger->credit(
			$wallet_id,
			number_format( (float) $amount, 2, '.', '' ),
			$ref_id,
			'deposit',
			'',
			array(
				'source'       => 'zeko_rewards',
				'reward_key'   => 'redemption',
				'type'         => 'reward_redemption',
				'points_spent' => $points,
			)
		);

		return array(
			'success'      => ! empty( $result['success'] ),
			'message'      => empty( $result['success'] ) ? ( $result['message'] ?? __( 'Redemption failed.', 'zeko-rewards' ) ) : __( 'Points redeemed to your wallet.', 'zeko-rewards' ),
			'reference_id' => $ref_id,
		);
	}
}
