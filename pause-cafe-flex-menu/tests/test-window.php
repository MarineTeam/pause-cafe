<?php
/**
 * Standalone check of the window resolver.
 *
 * PCFM_Window decides when every dish can be seen and bought, in every mode, so
 * it is worth being able to verify without a WordPress install. The handful of
 * WordPress functions it touches, and the three classes it collaborates with,
 * are stubbed below.
 *
 * Run from the plugin directory:
 *
 *     php tests/test-window.php
 */

define( 'ABSPATH', __DIR__ );

const TZ = 'America/Vancouver';

$GLOBALS['pcfm_meta'] = array();

function wp_timezone() {
	return new DateTimeZone( TZ );
}

function absint( $value ) {
	return abs( (int) $value );
}

function __( $text, $domain = null ) {
	return $text;
}

function get_option( $name, $default = false ) {
	if ( 'date_format' === $name ) {
		return 'j M Y';
	}

	if ( 'time_format' === $name ) {
		return 'g:i a';
	}

	return $default;
}

function wp_date( $format, $timestamp = null ) {
	$date = new DateTimeImmutable( '@' . $timestamp );

	return $date->setTimezone( new DateTimeZone( TZ ) )->format( $format );
}

function wp_cache_delete( $key, $group = '' ) {}

function get_post_meta( $post_id, $key, $single = false ) {
	return isset( $GLOBALS['pcfm_meta'][ $post_id ][ $key ] ) ? $GLOBALS['pcfm_meta'][ $post_id ][ $key ] : '';
}

class PCFM_Schedules {

	const TAXONOMY        = 'pcfm_schedule';
	const MODE_PLANNED    = 'planned';
	const MODE_ON_PUBLISH = 'on_publish';
	const MODE_MANUAL     = 'manual';

	public static $rules  = array();
	public static $assign = array();

	public static function default_rules() {
		return array(
			'mode'                     => self::MODE_PLANNED,
			'service_weekday'          => 0,
			'open_days_before'         => 5,
			'open_time'                => '12:00',
			'close_days_before'        => 1,
			'close_weekday'            => 6,
			'service_days_after_close' => 1,
			'close_time'               => '13:00',
			'preview_upcoming'         => 'no',
			'locations'                => array(),
			'location_offsets'         => array(),
			'default_capacity'         => 0,
			'default_price'            => '',
		);
	}

	public static function rules( $term_id ) {
		$stored = isset( self::$rules[ $term_id ] ) ? self::$rules[ $term_id ] : array();

		return array_merge( self::default_rules(), $stored );
	}

	public static function for_product( $product_id ) {
		return isset( self::$assign[ $product_id ] ) ? (int) self::$assign[ $product_id ] : 0;
	}

	public static function location_offset( $term_id, $location_term_id ) {
		$rules   = self::rules( $term_id );
		$offsets = $rules['location_offsets'];

		return isset( $offsets[ $location_term_id ] ) ? absint( $offsets[ $location_term_id ] ) : 0;
	}
}

class PCFM_Settings {

	public static $product_location = array();

	public static function location_for_product( $product_id ) {
		return isset( self::$product_location[ $product_id ] ) ? (int) self::$product_location[ $product_id ] : 0;
	}
}

class PCFM_Blackouts {

	public static $dates = array();

	public static function is_blackout( $date ) {
		return $date && isset( self::$dates[ $date ] );
	}

	public static function label( $date ) {
		return isset( self::$dates[ $date ] ) ? self::$dates[ $date ] : 'No menu this week';
	}
}

require __DIR__ . '/../includes/class-pcfm-window.php';

$pass = 0;
$fail = 0;

function check( $label, $actual, $expected ) {
	global $pass, $fail;

	if ( $actual === $expected ) {
		++$pass;
		echo "  ok    $label\n";

		return;
	}

	++$fail;
	echo "  FAIL  $label\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

function at( $when ) {
	return new DateTimeImmutable( $when, new DateTimeZone( TZ ) );
}

function dish( $id, array $meta, $schedule_id = 0, $location_id = 0 ) {
	$GLOBALS['pcfm_meta'][ $id ]              = $meta;
	PCFM_Schedules::$assign[ $id ]            = $schedule_id;
	PCFM_Settings::$product_location[ $id ]   = $location_id;

	PCFM_Window::flush();

	return PCFM_Window::for_product( $id );
}

function moment( $window, $which ) {
	return $window->$which ? $window->$which->format( 'D Y-m-d H:i' ) : null;
}

/* ------------------------------------------------------------------ */

echo "\nPlanned mode: service date drives everything\n";

PCFM_Schedules::$rules[1] = array( 'mode' => PCFM_Schedules::MODE_PLANNED );

$w = dish( 101, array( '_pcfm_service_date' => '2026-08-16' ), 1 );

check( 'source', $w->source, PCFM_Schedules::MODE_PLANNED );
check( 'opens Tuesday noon', moment( $w, 'open_from' ), 'Tue 2026-08-11 12:00' );
check( 'closes Saturday 1pm', moment( $w, 'close_at' ), 'Sat 2026-08-15 13:00' );
check( 'service date', $w->service_date, '2026-08-16' );

$cases = array(
	array( '2026-08-10 09:00', PCFM_Window::UPCOMING, 'Monday before' ),
	array( '2026-08-11 11:59', PCFM_Window::UPCOMING, 'one minute early' ),
	array( '2026-08-11 12:00', PCFM_Window::OPEN, 'exactly at open' ),
	array( '2026-08-15 12:59', PCFM_Window::OPEN, 'one minute before cutoff' ),
	array( '2026-08-15 13:00', PCFM_Window::CLOSED, 'exactly at cutoff' ),
	array( '2026-08-16 23:59', PCFM_Window::CLOSED, 'end of the service day' ),
	array( '2026-08-17 00:00', PCFM_Window::PAST, 'the day after' ),
);

foreach ( $cases as $case ) {
	list( $when, $expected, $label ) = $case;

	check( $label . ' (' . $when . ')', $w->state( at( $when ) ), $expected );
}

$w = dish( 102, array(), 1 );
check( 'no service date is NONE', $w->state( at( '2026-08-12 10:00' ) ), PCFM_Window::NONE );
check( 'no service date is not orderable', $w->is_orderable( at( '2026-08-12 10:00' ) ), false );

/* ------------------------------------------------------------------ */

echo "\nOn-publish mode: the moment of publishing drives everything\n";

PCFM_Schedules::$rules[2] = array( 'mode' => PCFM_Schedules::MODE_ON_PUBLISH );

$w = dish( 201, array( '_pcfm_opened_at' => '2026-08-11 14:30:00' ), 2 );

check( 'source', $w->source, PCFM_Schedules::MODE_ON_PUBLISH );
check( 'opens at the publish moment', moment( $w, 'open_from' ), 'Tue 2026-08-11 14:30' );
check( 'closes next Saturday 1pm', moment( $w, 'close_at' ), 'Sat 2026-08-15 13:00' );
check( 'service date derived', $w->service_date, '2026-08-16' );
check( 'open right after publishing', $w->state( at( '2026-08-11 14:31' ) ), PCFM_Window::OPEN );
check( 'closed after cutoff', $w->state( at( '2026-08-15 13:05' ) ), PCFM_Window::CLOSED );

$w = dish( 202, array( '_pcfm_opened_at' => '2026-08-15 13:00:00' ), 2 );
check( 'published exactly at cutoff rolls forward', moment( $w, 'close_at' ), 'Sat 2026-08-22 13:00' );

$w = dish( 203, array( '_pcfm_opened_at' => '2026-08-15 14:00:00' ), 2 );
check( 'published after cutoff rolls forward', moment( $w, 'close_at' ), 'Sat 2026-08-22 13:00' );

$w = dish( 204, array( '_pcfm_opened_at' => '2026-08-16 15:00:00' ), 2 );
check( 'republishing on Sunday reopens', $w->state( at( '2026-08-16 15:30' ) ), PCFM_Window::OPEN );

$w = dish( 205, array(), 2 );
check( 'never published is NONE', $w->state( at( '2026-08-12 10:00' ) ), PCFM_Window::NONE );

/* ------------------------------------------------------------------ */

echo "\nManual mode: the dish carries its own window\n";

PCFM_Schedules::$rules[3] = array( 'mode' => PCFM_Schedules::MODE_MANUAL );

$w = dish(
	301,
	array(
		'_pcfm_open_from' => '2026-08-10 08:00:00',
		'_pcfm_close_at'  => '2026-08-14 17:00:00',
	),
	3
);

check( 'source', $w->source, PCFM_Schedules::MODE_MANUAL );
check( 'opens when told', moment( $w, 'open_from' ), 'Mon 2026-08-10 08:00' );
check( 'closes when told', moment( $w, 'close_at' ), 'Fri 2026-08-14 17:00' );
check( 'service date derived from the close', $w->service_date, '2026-08-15' );
check( 'open inside the window', $w->state( at( '2026-08-12 12:00' ) ), PCFM_Window::OPEN );
check( 'closed after it', $w->state( at( '2026-08-14 17:01' ) ), PCFM_Window::CLOSED );

$w = dish( 302, array( '_pcfm_open_from' => '2026-08-10 08:00:00' ), 3 );
check( 'from without until is NONE', $w->state( at( '2026-08-12 10:00' ) ), PCFM_Window::NONE );

$w = dish(
	303,
	array(
		'_pcfm_open_from' => '2026-08-14 17:00:00',
		'_pcfm_close_at'  => '2026-08-10 08:00:00',
	),
	3
);
check( 'until before from is NONE', $w->state( at( '2026-08-12 10:00' ) ), PCFM_Window::NONE );

$w = dish(
	304,
	array(
		'_pcfm_open_from'    => '2026-08-10 08:00:00',
		'_pcfm_close_at'     => '2026-08-14 17:00:00',
		'_pcfm_service_date' => '2026-08-20',
	),
	3
);
check( 'an explicit service date wins over the derived one', $w->service_date, '2026-08-20' );

/* ------------------------------------------------------------------ */

echo "\nPrecedence: a per-dish window overrides its schedule\n";

$w = dish(
	401,
	array(
		'_pcfm_service_date' => '2026-08-16',
		'_pcfm_open_from'    => '2026-08-09 08:00:00',
		'_pcfm_close_at'     => '2026-08-13 20:00:00',
	),
	1
);

check( 'source is override, not planned', $w->source, 'override' );
check( 'override supplies the open', moment( $w, 'open_from' ), 'Sun 2026-08-09 08:00' );
check( 'override supplies the close', moment( $w, 'close_at' ), 'Thu 2026-08-13 20:00' );
check( 'the explicit service date is still honoured', $w->service_date, '2026-08-16' );
check( 'open when the planned window would not be', $w->state( at( '2026-08-10 09:00' ) ), PCFM_Window::OPEN );
check( 'closed when the planned window still would be open', $w->state( at( '2026-08-14 09:00' ) ), PCFM_Window::CLOSED );

$w = dish(
	402,
	array(
		'_pcfm_opened_at' => '2026-08-11 14:30:00',
		'_pcfm_open_from' => '2026-08-09 08:00:00',
		'_pcfm_close_at'  => '2026-08-13 20:00:00',
	),
	2
);
check( 'override beats on-publish too', moment( $w, 'close_at' ), 'Thu 2026-08-13 20:00' );

/* ------------------------------------------------------------------ */

echo "\nPer-location cutoff offsets pull the close earlier, never later\n";

PCFM_Schedules::$rules[5] = array(
	'mode'             => PCFM_Schedules::MODE_PLANNED,
	'location_offsets' => array(
		77 => 180,   // three hours earlier
		88 => 0,
		99 => 100000, // absurd, to prove it cannot invert the window
	),
);

$w = dish( 501, array( '_pcfm_service_date' => '2026-08-16' ), 5, 77 );
check( 'offset location closes three hours earlier', moment( $w, 'close_at' ), 'Sat 2026-08-15 10:00' );
check( 'and is closed while the others are open', $w->state( at( '2026-08-15 11:00' ) ), PCFM_Window::CLOSED );

$w = dish( 502, array( '_pcfm_service_date' => '2026-08-16' ), 5, 88 );
check( 'unoffset location keeps the schedule cutoff', moment( $w, 'close_at' ), 'Sat 2026-08-15 13:00' );
check( 'and is still open at that time', $w->state( at( '2026-08-15 11:00' ) ), PCFM_Window::OPEN );

$w = dish( 503, array( '_pcfm_service_date' => '2026-08-16' ), 5, 99 );
check( 'an absurd offset clamps to the open time', moment( $w, 'close_at' ), moment( $w, 'open_from' ) );
check( 'leaving a window that never opens', $w->state( at( '2026-08-13 09:00' ) ), PCFM_Window::CLOSED );

$w = dish( 504, array( '_pcfm_service_date' => '2026-08-16' ), 5, 0 );
check( 'a dish with no location is unaffected', moment( $w, 'close_at' ), 'Sat 2026-08-15 13:00' );

/* ------------------------------------------------------------------ */

echo "\nBlackout dates void the window\n";

PCFM_Blackouts::$dates = array( '2026-12-27' => 'Christmas — no lunch' );

PCFM_Schedules::$rules[6] = array( 'mode' => PCFM_Schedules::MODE_PLANNED );

$w = dish( 601, array( '_pcfm_service_date' => '2026-12-27' ), 6 );
check( 'source becomes blackout', $w->source, PCFM_Window::BLACKOUT );
check( 'state is blackout', $w->state( at( '2026-12-23 10:00' ) ), PCFM_Window::BLACKOUT );
check( 'not orderable', $w->is_orderable( at( '2026-12-23 10:00' ) ), false );
check( 'not listed', $w->is_listed( at( '2026-12-23 10:00' ) ), false );
check( 'the label is the message', $w->message(), 'Christmas — no lunch' );

$w = dish( 602, array( '_pcfm_service_date' => '2026-12-20' ), 6 );
check( 'the week before is unaffected', $w->state( at( '2026-12-16 13:00' ) ), PCFM_Window::OPEN );

PCFM_Blackouts::$dates = array();

/* ------------------------------------------------------------------ */

echo "\nTwo schedules resolve independently\n";

PCFM_Schedules::$rules[7] = array(
	'mode'              => PCFM_Schedules::MODE_PLANNED,
	'service_weekday'   => 0,
	'open_days_before'  => 5,
	'close_days_before' => 1,
	'close_time'        => '13:00',
);

PCFM_Schedules::$rules[8] = array(
	'mode'              => PCFM_Schedules::MODE_PLANNED,
	'service_weekday'   => 3,
	'open_days_before'  => 3,
	'open_time'         => '09:00',
	'close_days_before' => 1,
	'close_time'        => '18:00',
);

$sunday    = dish( 701, array( '_pcfm_service_date' => '2026-08-16' ), 7 );
$wednesday = dish( 801, array( '_pcfm_service_date' => '2026-08-19' ), 8 );

check( 'Sunday lunch closes Saturday 1pm', moment( $sunday, 'close_at' ), 'Sat 2026-08-15 13:00' );
check( 'Wednesday supper closes Tuesday 6pm', moment( $wednesday, 'close_at' ), 'Tue 2026-08-18 18:00' );
check( 'Wednesday supper opens Sunday 9am', moment( $wednesday, 'open_from' ), 'Sun 2026-08-16 09:00' );
check( 'Sunday lunch is closed on Monday', $sunday->state( at( '2026-08-17 10:00' ) ), PCFM_Window::PAST );
check( 'Wednesday supper is open on Monday', $wednesday->state( at( '2026-08-17 10:00' ) ), PCFM_Window::OPEN );

/* ------------------------------------------------------------------ */

echo "\nPreview controls whether an unopened week is listed\n";

PCFM_Schedules::$rules[9]  = array( 'mode' => PCFM_Schedules::MODE_PLANNED );
PCFM_Schedules::$rules[10] = array(
	'mode'             => PCFM_Schedules::MODE_PLANNED,
	'preview_upcoming' => 'yes',
);

$w = dish( 901, array( '_pcfm_service_date' => '2026-08-16' ), 9 );
check( 'preview off: upcoming is not listed', $w->is_listed( at( '2026-08-10 09:00' ) ), false );
check( 'preview off: open is listed', $w->is_listed( at( '2026-08-12 09:00' ) ), true );
check( 'preview off: closed is listed', $w->is_listed( at( '2026-08-16 09:00' ) ), true );
check( 'preview off: past is not listed', $w->is_listed( at( '2026-08-18 09:00' ) ), false );

$w = dish( 1001, array( '_pcfm_service_date' => '2026-08-16' ), 10 );
check( 'preview on: upcoming is listed', $w->is_listed( at( '2026-08-10 09:00' ) ), true );

/* ------------------------------------------------------------------ */

echo "\nBad data fails closed rather than becoming buyable\n";

$w = dish( 1101, array( '_pcfm_service_date' => 'next sunday' ), 1 );
check( 'garbage service date is NONE', $w->state( at( '2026-08-12 10:00' ) ), PCFM_Window::NONE );

$w = dish( 1102, array( '_pcfm_service_date' => '2026-02-31' ), 1 );
check( 'Feb 31 is NONE', $w->state( at( '2026-02-01 10:00' ) ), PCFM_Window::NONE );

$w = dish( 1103, array( '_pcfm_opened_at' => 'yesterday' ), 2 );
check( 'garbage publish stamp is NONE', $w->state( at( '2026-08-12 10:00' ) ), PCFM_Window::NONE );

$w = dish( 1104, array() );
check( 'no schedule and no dates is NONE', $w->state( at( '2026-08-12 10:00' ) ), PCFM_Window::NONE );
check( 'and is not orderable', $w->is_orderable( at( '2026-08-12 10:00' ) ), false );
check( 'and is not listed', $w->is_listed( at( '2026-08-12 10:00' ) ), false );

/* ------------------------------------------------------------------ */

echo "\nDaylight saving boundary (clocks go back Sunday 1 November 2026)\n";

$w = dish( 1201, array( '_pcfm_service_date' => '2026-11-01' ), 1 );
check( 'opens Tuesday before the change', moment( $w, 'open_from' ), 'Tue 2026-10-27 12:00' );
check( 'closes Saturday 1pm local', moment( $w, 'close_at' ), 'Sat 2026-10-31 13:00' );
check( 'still open Saturday 12:59', $w->state( at( '2026-10-31 12:59' ) ), PCFM_Window::OPEN );
check( 'closed on Sunday after the clocks change', $w->state( at( '2026-11-01 09:00' ) ), PCFM_Window::CLOSED );

$w = dish( 1202, array( '_pcfm_opened_at' => '2026-10-27 14:00:00' ), 2 );
check( 'on-publish spans the change too', moment( $w, 'close_at' ), 'Sat 2026-10-31 13:00' );
check( 'and its service date lands the day after', $w->service_date, '2026-11-01' );

echo "\n$pass passed, $fail failed\n";

exit( $fail > 0 ? 1 : 0 );
