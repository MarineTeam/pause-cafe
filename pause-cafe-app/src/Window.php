<?php

namespace PauseCafe;

use DateTimeImmutable;

/**
 * When one dish can be seen and bought.
 *
 * Whatever mode is active and whatever overrides a dish carries, the answer is
 * always these three values: when ordering opens, when it shuts, and the day
 * the food is handed over. The menu, the cart guard and the kitchen report
 * consume this and nothing else.
 */
class Window {

	public const UPCOMING = 'upcoming';
	public const OPEN     = 'open';
	public const CLOSED   = 'closed';
	public const PAST     = 'past';
	public const BLACKOUT = 'blackout';
	public const NONE     = 'none';

	public ?DateTimeImmutable $openFrom = null;

	public ?DateTimeImmutable $closeAt = null;

	public string $serviceDate = '';

	public string $source = self::NONE;

	public string $blackoutLabel = '';

	public bool $preview = false;

	public function expiresAt(): ?DateTimeImmutable {
		$service = Schedule::parseDate( $this->serviceDate );

		if ( $service ) {
			return $service->setTime( 23, 59, 59 );
		}

		return $this->closeAt?->setTime( 23, 59, 59 );
	}

	/**
	 * Anything unresolvable reads as NONE, which is never orderable, so broken
	 * data fails closed rather than quietly becoming buyable.
	 */
	public function state( ?DateTimeImmutable $now = null ): string {
		if ( self::BLACKOUT === $this->source ) {
			return self::BLACKOUT;
		}

		if ( self::NONE === $this->source || ! $this->openFrom || ! $this->closeAt ) {
			return self::NONE;
		}

		$now     = $now ?: Schedule::now();
		$expires = $this->expiresAt();

		if ( $expires && $now > $expires ) {
			return self::PAST;
		}

		if ( $now < $this->openFrom ) {
			return self::UPCOMING;
		}

		if ( $now >= $this->closeAt ) {
			return self::CLOSED;
		}

		return self::OPEN;
	}

	public function isOrderable( ?DateTimeImmutable $now = null ): bool {
		return self::OPEN === $this->state( $now );
	}

	/**
	 * Dishes stay listed through the end of the service day so people can check
	 * what they ordered. Weeks that have not opened show only if previews are on.
	 */
	public function isListed( ?DateTimeImmutable $now = null ): bool {
		$state = $this->state( $now );

		if ( self::OPEN === $state || self::CLOSED === $state ) {
			return true;
		}

		return self::UPCOMING === $state && $this->preview;
	}

	/**
	 * The sentence shown in place of an add-to-cart button, so a missing button
	 * is never unexplained.
	 */
	public function message(): string {
		return match ( $this->state() ) {
			self::OPEN     => 'Ordering closes ' . Schedule::formatMoment( $this->closeAt ) . '.',
			self::UPCOMING => 'Ordering opens ' . Schedule::formatMoment( $this->openFrom ) . '.',
			self::CLOSED   => 'Ordering closed ' . Schedule::formatMoment( $this->closeAt ) . '.',
			self::BLACKOUT => $this->blackoutLabel,
			default        => 'This dish is not currently available.',
		};
	}
}
