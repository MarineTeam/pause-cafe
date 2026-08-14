<?php

namespace PauseCafe\Payments;

/**
 * A way of paying for an order.
 *
 * Implement this and register it with `Payments::register()` to add one. The
 * ordering code never names a payment method, so nothing else has to change:
 * the checkout offers whatever is enabled, and settings grows a toggle for it
 * automatically.
 */
interface Method {

	/** Stable identifier, stored on the order. Lowercase, no spaces. */
	public function id(): string;

	/** Shown to the person paying. */
	public function label(): string;

	/** One line under the label at checkout. */
	public function description(): string;

	/**
	 * Whether paying is settled the instant the order is placed.
	 *
	 * A wallet debit is. Cash on pickup is not, and leaves the order owing until
	 * an organiser marks it paid.
	 */
	public function settlesImmediately(): bool;

	/**
	 * Why this method cannot be used for this order right now.
	 *
	 * @return string Empty when it can be used. Anything else is shown to the
	 *                customer and blocks checkout.
	 */
	public function unavailableReason( int $userId, int $totalCents ): string;

	/**
	 * Takes payment. Called inside the order's transaction, so anything written
	 * here is rolled back with the order if a later step fails.
	 *
	 * Must not start or commit a transaction of its own.
	 */
	public function charge( int $userId, int $orderId, int $totalCents, ?int $byUserId ): void;

	/**
	 * Gives it back. Called inside the cancellation's transaction, under the
	 * same rules as charge().
	 */
	public function refund( int $userId, int $orderId, int $totalCents, ?int $byUserId ): void;

	/**
	 * Moves money after the order was placed, for an edit or a partial refund.
	 *
	 * Separate from charge() and refund() rather than a parameter on them,
	 * because those two carry fixed wallet references — `order:12`, `refund:12`
	 * — and a unique index makes each usable exactly once. That is deliberate:
	 * it is what stops a redelivered Zeffy webhook crediting twice. An edited
	 * order needs many movements, so each brings its own reference instead of
	 * loosening the guard that protects the other two.
	 *
	 * @param int    $deltaCents Positive takes more; negative gives money back.
	 * @param string $reference  Unique per movement.
	 */
	public function adjust(
		int $userId,
		int $orderId,
		int $deltaCents,
		string $reason,
		string $reference,
		?int $byUserId
	): void;
}
