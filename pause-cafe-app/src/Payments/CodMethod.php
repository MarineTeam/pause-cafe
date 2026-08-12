<?php

namespace PauseCafe\Payments;

/**
 * Pay when the food is handed over.
 *
 * Nothing financial is recorded at checkout. The order is placed owing, and an
 * organiser marks it paid once the cash is in hand — which is what makes the
 * unpaid list on the orders screen worth reading.
 */
class CodMethod implements Method {

	public function id(): string {
		return 'cod';
	}

	public function label(): string {
		return 'Pay on pickup';
	}

	public function description(): string {
		return 'Bring cash on the day. An organiser will mark it paid.';
	}

	public function settlesImmediately(): bool {
		return false;
	}

	public function unavailableReason( int $userId, int $totalCents ): string {
		return '';
	}

	public function charge( int $userId, int $orderId, int $totalCents, ?int $byUserId ): void {
		// Nothing to take yet. The order carries its own unpaid state.
	}

	public function refund( int $userId, int $orderId, int $totalCents, ?int $byUserId ): void {
		/*
		 * If the cash was never collected there is nothing to give back. If it
		 * was, handing it over happens in person -- the cancellation notice says
		 * so rather than inventing a ledger entry for money the system never
		 * held.
		 */
	}
}
