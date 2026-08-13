<?php

namespace PauseCafe;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The rules engine, in three interchangeable modes.
 *
 *   planned     a dish carries a service date; the window is worked back from it
 *   on_publish  publishing opens ordering; it runs to the next cutoff
 *   manual      the dish carries its own from and until
 *
 * One mode is active at a time, chosen in settings. Whichever it is, a per-dish
 * from/until always wins, and a blackout date always voids.
 */
class Schedule {

	public const MODE_PLANNED    = 'planned';
	public const MODE_ON_PUBLISH = 'on_publish';
	public const MODE_MANUAL     = 'manual';

	private static ?DateTimeZone $timezone = null;

	private static ?DateTimeImmutable $frozenNow = null;

	public static function configure( string $timezone ): void {
		self::$timezone = new DateTimeZone( $timezone );
	}

	public static function timezone(): DateTimeZone {
		return self::$timezone ?: new DateTimeZone( 'UTC' );
	}

	public static function now(): DateTimeImmutable {
		return self::$frozenNow ?: new DateTimeImmutable( 'now', self::timezone() );
	}

	/** Lets tests pin the clock. */
	public static function freeze( ?DateTimeImmutable $now ): void {
		self::$frozenNow = $now;
	}

	public static function modes(): array {
		return array(
			self::MODE_PLANNED    => 'Planned ahead — each dish carries a service date',
			self::MODE_ON_PUBLISH => 'On publish — ordering opens the moment a dish goes live',
			self::MODE_MANUAL     => 'Manual — each dish carries its own from and until',
		);
	}

	public static function activeMode(): string {
		$mode = Settings::get( 'active_mode' );

		return array_key_exists( $mode, self::modes() ) ? $mode : self::MODE_PLANNED;
	}

	public static function parseDateTime( string $value ): ?DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, self::timezone() );

		if ( $parsed ) {
			return $parsed;
		}

		// datetime-local inputs post without seconds.
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, self::timezone() );

		return $parsed ? $parsed->setTime( (int) $parsed->format( 'H' ), (int) $parsed->format( 'i' ), 0 ) : null;
	}

	public static function parseDate( string $value ): ?DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value . ' 00:00:00', self::timezone() );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $parsed;
	}

	private static function applyTime( DateTimeImmutable $date, string $time, int $fallbackHour ): DateTimeImmutable {
		$parts   = explode( ':', $time );
		$hours   = isset( $parts[0] ) && '' !== $parts[0] ? (int) $parts[0] : $fallbackHour;
		$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return $date->setTime( $hours, $minutes, 0 );
	}

	/**
	 * The first occurrence of a weekday at a time, strictly after the moment
	 * given. Strictly matters: a menu published at the cutoff has missed it and
	 * must run to the following week rather than close in the past.
	 */
	public static function nextWeekdayAt( DateTimeImmutable $moment, int $weekday, string $time ): DateTimeImmutable {
		$daysAhead = ( $weekday - (int) $moment->format( 'w' ) + 7 ) % 7;
		$candidate = self::applyTime( $moment->modify( '+' . $daysAhead . ' days' ), $time, 13 );

		if ( $candidate <= $moment ) {
			$candidate = $candidate->modify( '+7 days' );
		}

		return $candidate;
	}

	/**
	 * Resolves one menu_items row into a Window.
	 *
	 * @param array $item Row from menu_items.
	 */
	public static function forItem( array $item ): Window {
		// Which rules apply is a property of the dish's schedule, not of the
		// site: two menus can run side by side on different rhythms.
		$rules = Schedules::rulesFor( $item['schedule_id'] ?? Schedules::DEFAULT_ID );

		$window          = new Window();
		$window->preview = (bool) $rules['preview_upcoming'];

		$serviceMeta = (string) ( $item['service_date'] ?? '' );
		$from        = self::parseDateTime( (string) ( $item['open_from'] ?? '' ) );
		$until       = self::parseDateTime( (string) ( $item['close_at'] ?? '' ) );
		$mode        = (string) $rules['mode'];

		if ( $from && $until && $until > $from ) {
			// A dish carrying its own window always wins.
			$window->openFrom = $from;
			$window->closeAt  = $until;
			$window->source   = self::MODE_MANUAL === $mode ? self::MODE_MANUAL : 'override';
		} elseif ( self::MODE_PLANNED === $mode ) {
			$service = self::parseDate( $serviceMeta );

			if ( $service ) {
				$window->openFrom = self::applyTime(
					$service->modify( '-' . (int) $rules['open_days_before'] . ' days' ),
					(string) $rules['open_time'],
					12
				);

				$window->closeAt = self::applyTime(
					$service->modify( '-' . (int) $rules['close_days_before'] . ' days' ),
					(string) $rules['close_time'],
					13
				);

				$window->source = self::MODE_PLANNED;
			}
		} elseif ( self::MODE_ON_PUBLISH === $mode ) {
			$opened = self::parseDateTime( (string) ( $item['opened_at'] ?? '' ) );

			if ( $opened ) {
				$window->openFrom = $opened;
				$window->closeAt  = self::nextWeekdayAt(
					$opened,
					(int) $rules['close_weekday'],
					(string) $rules['close_time']
				);

				$window->source = self::MODE_ON_PUBLISH;
			}
		}

		if ( Window::NONE === $window->source ) {
			return $window;
		}

		$window->serviceDate = self::deriveServiceDate( $window, $serviceMeta, (int) $rules['service_days_after_close'] );

		if ( '' !== $window->serviceDate && Blackouts::isBlackout( $window->serviceDate ) ) {
			$window->blackoutLabel = Blackouts::label( $window->serviceDate );
			$window->source        = Window::BLACKOUT;
		}

		return $window;
	}

	/**
	 * Every mode produces a service date, which is what lets one kitchen report
	 * cover all three without knowing which is in force.
	 */
	private static function deriveServiceDate( Window $window, string $explicit, int $daysAfterClose ): string {
		if ( '' !== $explicit && self::parseDate( $explicit ) ) {
			return $explicit;
		}

		if ( ! $window->closeAt ) {
			return '';
		}

		return $window->closeAt
			->modify( '+' . $daysAfterClose . ' days' )
			->format( 'Y-m-d' );
	}

	/**
	 * Service weekdays falling inside a month, for the planned-mode builder.
	 *
	 * @return string[]
	 */
	public static function serviceDatesInMonth( int $year, int $month, ?int $weekday = null ): array {
		$weekday = null !== $weekday ? $weekday : Settings::int( 'service_weekday', 0 );
		$dates   = array();

		$cursor = DateTimeImmutable::createFromFormat(
			'Y-n-j H:i:s',
			$year . '-' . $month . '-1 00:00:00',
			self::timezone()
		);

		if ( ! $cursor ) {
			return $dates;
		}

		$days = (int) $cursor->format( 't' );

		for ( $day = 1; $day <= $days; $day++ ) {
			$candidate = $cursor->setDate( $year, $month, $day );

			if ( (int) $candidate->format( 'w' ) === $weekday ) {
				$dates[] = $candidate->format( 'Y-m-d' );
			}
		}

		return $dates;
	}

	public static function formatMoment( ?DateTimeImmutable $moment ): string {
		return $moment ? $moment->format( 'D j M \a\t g:ia' ) : '';
	}

	public static function formatDate( string $date, string $format = 'l j F Y' ): string {
		$parsed = self::parseDate( $date );

		return $parsed ? $parsed->format( $format ) : '';
	}
}
