<?php
/**
 * The resolver. Every ordering decision in this plugin comes through here.
 *
 * Whatever mode a schedule runs in, and whatever overrides a dish carries, the
 * answer is always the same three values: when ordering opens, when it shuts,
 * and the day the food is handed over. Visibility, the cart guard, the menu and
 * the kitchen report consume those and nothing else, so a new mode means
 * teaching one class rather than four.
 *
 * Resolution order:
 *
 *   1. Per-dish from/until, which wins outright
 *   2. The schedule's mode
 *   3. The location's cutoff offset, which can only pull the close earlier
 *   4. Blackout dates, which void the window entirely
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Window {

	const UPCOMING = 'upcoming';
	const OPEN     = 'open';
	const CLOSED   = 'closed';
	const PAST     = 'past';
	const BLACKOUT = 'blackout';
	const NONE     = 'none';

	const META_SERVICE_DATE = '_pcfm_service_date';
	const META_OPENED_AT    = '_pcfm_opened_at';
	const META_OPEN_FROM    = '_pcfm_open_from';
	const META_CLOSE_AT     = '_pcfm_close_at';

	/** @var DateTimeImmutable|null */
	public $open_from = null;

	/** @var DateTimeImmutable|null */
	public $close_at = null;

	/** @var string Y-m-d */
	public $service_date = '';

	/** @var string One of the mode constants, plus 'override', 'blackout' or 'none'. */
	public $source = self::NONE;

	public $blackout_label = '';
	public $schedule_id    = 0;
	public $location_id    = 0;
	public $preview        = false;

	private static $cache = array();

	public static function flush() {
		self::$cache = array();
		wp_cache_delete( 'pcfm_service_dates' );
	}

	public static function timezone() {
		return wp_timezone();
	}

	public static function now() {
		return new DateTimeImmutable( 'now', self::timezone() );
	}

	/**
	 * @return DateTimeImmutable|null
	 */
	public static function parse_datetime( $value ) {
		if ( ! $value ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, self::timezone() );

		if ( $parsed ) {
			return $parsed;
		}

		// Datetime-local inputs post without seconds.
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, self::timezone() );

		return $parsed ? $parsed->setTime( (int) $parsed->format( 'H' ), (int) $parsed->format( 'i' ), 0 ) : null;
	}

	/**
	 * @return DateTimeImmutable|null Local midnight on that date, or null if the
	 *                                string is not a real calendar date.
	 */
	public static function parse_date( $value ) {
		if ( ! $value ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value . ' 00:00:00', self::timezone() );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $parsed;
	}

	private static function apply_time( DateTimeImmutable $date, $time, $fallback_hour = 0 ) {
		$parts   = explode( ':', (string) $time );
		$hours   = isset( $parts[0] ) && '' !== $parts[0] ? (int) $parts[0] : $fallback_hour;
		$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return $date->setTime( $hours, $minutes, 0 );
	}

	/**
	 * The first occurrence of a weekday at a time, strictly after the given
	 * moment. Strictly matters: a menu published at the cutoff has missed it, and
	 * must run to the following week rather than close in the past.
	 */
	public static function next_weekday_at( DateTimeImmutable $moment, $weekday, $time ) {
		$days_ahead = ( (int) $weekday - (int) $moment->format( 'w' ) + 7 ) % 7;
		$candidate  = self::apply_time( $moment->modify( '+' . $days_ahead . ' days' ), $time, 13 );

		if ( $candidate <= $moment ) {
			$candidate = $candidate->modify( '+7 days' );
		}

		return $candidate;
	}

	public static function for_product( $product_id ) {
		$product_id = (int) $product_id;

		if ( isset( self::$cache[ $product_id ] ) ) {
			return self::$cache[ $product_id ];
		}

		$window = self::resolve( $product_id );

		self::$cache[ $product_id ] = $window;

		return $window;
	}

	private static function resolve( $product_id ) {
		$window              = new self();
		$window->schedule_id = PCFM_Schedules::for_product( $product_id );
		$window->location_id = PCFM_Settings::location_for_product( $product_id );

		$rules            = PCFM_Schedules::rules( $window->schedule_id );
		$window->preview  = 'yes' === $rules['preview_upcoming'];
		$explicit_service = (string) get_post_meta( $product_id, self::META_SERVICE_DATE, true );

		$from  = self::parse_datetime( get_post_meta( $product_id, self::META_OPEN_FROM, true ) );
		$until = self::parse_datetime( get_post_meta( $product_id, self::META_CLOSE_AT, true ) );

		if ( $from && $until && $until > $from ) {
			// Step 1: a dish carrying its own window always wins.
			$window->open_from = $from;
			$window->close_at  = $until;
			$window->source    = PCFM_Schedules::MODE_MANUAL === $rules['mode'] ? PCFM_Schedules::MODE_MANUAL : 'override';
		} elseif ( PCFM_Schedules::MODE_PLANNED === $rules['mode'] ) {
			$service = self::parse_date( $explicit_service );

			if ( $service ) {
				$window->open_from = self::apply_time(
					$service->modify( '-' . absint( $rules['open_days_before'] ) . ' days' ),
					$rules['open_time'],
					12
				);

				$window->close_at = self::apply_time(
					$service->modify( '-' . absint( $rules['close_days_before'] ) . ' days' ),
					$rules['close_time'],
					13
				);

				$window->source = PCFM_Schedules::MODE_PLANNED;
			}
		} elseif ( PCFM_Schedules::MODE_ON_PUBLISH === $rules['mode'] ) {
			$opened = self::parse_datetime( get_post_meta( $product_id, self::META_OPENED_AT, true ) );

			if ( $opened ) {
				$window->open_from = $opened;
				$window->close_at  = self::next_weekday_at( $opened, $rules['close_weekday'], $rules['close_time'] );
				$window->source    = PCFM_Schedules::MODE_ON_PUBLISH;
			}
		}

		if ( self::NONE === $window->source ) {
			return $window;
		}

		$window->service_date = self::derive_service_date( $window, $rules, $explicit_service );

		self::apply_location_offset( $window, $rules );

		// Step 4: a blacked-out service day voids the whole window.
		if ( $window->service_date && PCFM_Blackouts::is_blackout( $window->service_date ) ) {
			$window->blackout_label = PCFM_Blackouts::label( $window->service_date );
			$window->source         = self::BLACKOUT;
		}

		return $window;
	}

	/**
	 * Every mode produces a service date, which is what lets the kitchen report
	 * group orders without caring how the window was worked out.
	 */
	private static function derive_service_date( self $window, array $rules, $explicit ) {
		if ( PCFM_Schedules::MODE_PLANNED === $window->source && $explicit ) {
			return $explicit;
		}

		// An explicitly set date beats anything derived, in any mode.
		if ( $explicit && self::parse_date( $explicit ) ) {
			return $explicit;
		}

		if ( ! $window->close_at ) {
			return '';
		}

		return $window->close_at
			->modify( '+' . absint( $rules['service_days_after_close'] ) . ' days' )
			->format( 'Y-m-d' );
	}

	/**
	 * A location can shut earlier than the rest of its schedule, never later.
	 *
	 * An offset large enough to land before the window opens leaves a zero-length
	 * window, so a misconfigured offset fails closed instead of quietly reopening
	 * something.
	 */
	private static function apply_location_offset( self $window, array $rules ) {
		if ( ! $window->close_at || ! $window->location_id || ! $window->schedule_id ) {
			return;
		}

		$offset = PCFM_Schedules::location_offset( $window->schedule_id, $window->location_id );

		if ( $offset <= 0 ) {
			return;
		}

		$adjusted = $window->close_at->modify( '-' . $offset . ' minutes' );

		if ( $window->open_from && $adjusted < $window->open_from ) {
			$adjusted = $window->open_from;
		}

		$window->close_at = $adjusted;
	}

	public function is_void() {
		return self::NONE === $this->source || self::BLACKOUT === $this->source;
	}

	/**
	 * Dishes stay listed through the end of the day they are served, then drop off.
	 */
	public function expires_at() {
		$service = self::parse_date( $this->service_date );

		if ( $service ) {
			return $service->setTime( 23, 59, 59 );
		}

		return $this->close_at ? $this->close_at->setTime( 23, 59, 59 ) : null;
	}

	/**
	 * @return string One of the state constants. Anything unresolvable reads as
	 *                NONE, which is never orderable, so broken data fails closed.
	 */
	public function state( ?DateTimeImmutable $now = null ) {
		if ( self::BLACKOUT === $this->source ) {
			return self::BLACKOUT;
		}

		if ( self::NONE === $this->source || ! $this->open_from || ! $this->close_at ) {
			return self::NONE;
		}

		$expires = $this->expires_at();
		$now     = $now ? $now : self::now();

		if ( $expires && $now > $expires ) {
			return self::PAST;
		}

		if ( $now < $this->open_from ) {
			return self::UPCOMING;
		}

		if ( $now >= $this->close_at ) {
			return self::CLOSED;
		}

		return self::OPEN;
	}

	public function is_orderable( ?DateTimeImmutable $now = null ) {
		return self::OPEN === $this->state( $now );
	}

	public function is_listed( ?DateTimeImmutable $now = null ) {
		$state = $this->state( $now );

		if ( self::OPEN === $state || self::CLOSED === $state ) {
			return true;
		}

		return self::UPCOMING === $state && $this->preview;
	}

	public function format_moment( ?DateTimeImmutable $moment = null ) {
		if ( ! $moment ) {
			return '';
		}

		return wp_date(
			get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ),
			$moment->getTimestamp()
		);
	}

	/**
	 * Customer-facing explanation. Replaces a silently missing add-to-cart button
	 * with a sentence saying why it is not there.
	 */
	public function message() {
		switch ( $this->state() ) {
			case self::OPEN:
				return sprintf(
					/* translators: %s: date and time ordering closes. */
					__( 'Ordering closes %s.', 'pause-cafe-flex-menu' ),
					$this->format_moment( $this->close_at )
				);

			case self::UPCOMING:
				return sprintf(
					/* translators: %s: date and time ordering opens. */
					__( 'Ordering opens %s.', 'pause-cafe-flex-menu' ),
					$this->format_moment( $this->open_from )
				);

			case self::CLOSED:
				return sprintf(
					/* translators: %s: date and time ordering closed. */
					__( 'Ordering closed %s.', 'pause-cafe-flex-menu' ),
					$this->format_moment( $this->close_at )
				);

			case self::BLACKOUT:
				return $this->blackout_label;
		}

		return __( 'This dish is not currently available.', 'pause-cafe-flex-menu' );
	}

	public static function format_date( $date, $format = null ) {
		$parsed = self::parse_date( $date );

		if ( ! $parsed ) {
			return '';
		}

		return wp_date( $format ? $format : get_option( 'date_format' ), $parsed->getTimestamp() );
	}
}
