<?php

namespace PauseCafe\Payments;

use PauseCafe\Money;
use PauseCafe\Settings;
use PauseCafe\Wallet;

/**
 * Pay from a prepaid balance.
 *
 * Settles immediately: the order is paid the moment it is placed, and the
 * ledger entry is written inside the order's own transaction.
 */
class WalletMethod implements Method {

	public function id(): string {
		return 'wallet';
	}

	public function label(): string {
		return 'Wallet balance';
	}

	public function description(): string {
		return 'Taken from what you have already topped up.';
	}

	public function settlesImmediately(): bool {
		return true;
	}

	public function unavailableReason( int $userId, int $totalCents ): string {
		if ( Settings::bool( 'allow_negative_balance' ) ) {
			return '';
		}

		$balance = Wallet::balance( $userId );

		if ( $balance >= $totalCents ) {
			return '';
		}

		return 'Your balance is ' . Money::format( $balance ) . ' and this order comes to ' .
			Money::format( $totalCents ) . '. Please top up first.';
	}

	public function charge( int $userId, int $orderId, int $totalCents, ?int $byUserId ): void {
		Wallet::post(
			$userId,
			-$totalCents,
			Wallet::KIND_ORDER,
			'Order #' . $orderId,
			'order:' . $orderId,
			$byUserId,
			false
		);
	}

	public function refund( int $userId, int $orderId, int $totalCents, ?int $byUserId ): void {
		Wallet::post(
			$userId,
			$totalCents,
			Wallet::KIND_REFUND,
			'Refund for cancelled order #' . $orderId,
			'refund:' . $orderId,
			$byUserId,
			false
		);
	}
}
