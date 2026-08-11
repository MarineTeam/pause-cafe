<?php
/**
 * Standalone check of the publish-triggered ordering rules.
 *
 * PCLM_Schedule decides when every dish can be seen and bought, so it is worth
 * being able to verify without a WordPress install. The handful of WordPress
 * functions it touches are stubbed below.
 *
 * Run from the plugin directory:
 *
 *     php tests/test-schedule.php
 */

define( 'ABSPATH', __DIR__ );

const TZ = 'America/Vancouver';

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

function wp_cache_get( $key, $group = '' ) {
	return false;
}

function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {}

function wp_cache_delete( $key, $group = '' ) {}

class PCLM_Settings {

	public static $overrides = array();

	public static function get( $key ) {
		$defaults = array(
			'close_weekday'            => 6,
			'close_time'               => '13:00',
			'service_days_after_close' => 1,
		);

		$all = array_merge( $defaults, self::$overrides );

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}
}

class PCLM_Product {
	const META_CYCLE = '_pclm_cycle';
}

require __DIR__ . '/../includes/class-pclm-schedule.php';

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

function cutoff( $when ) {
	return PCLM_Schedule::cutoff_after( at( $when ) )->format( 'D Y-m-d H:i' );
}

echo "\nCutoff is the first Saturday 13:00 strictly after publishing\n";

check( 'published Tuesday afternoon', cutoff( '2026-08-11 14:30' ), 'Sat 2026-08-15 13:00' );
check( 'published Wednesday', cutoff( '2026-08-12 09:00' ), 'Sat 2026-08-15 13:00' );
check( 'published Friday night', cutoff( '2026-08-14 23:00' ), 'Sat 2026-08-15 13:00' );
check( 'published Saturday morning', cutoff( '2026-08-15 09:00' ), 'Sat 2026-08-15 13:00' );
check( 'published exactly at cutoff rolls forward', cutoff( '2026-08-15 13:00' ), 'Sat 2026-08-22 13:00' );
check( 'published just after cutoff rolls forward', cutoff( '2026-08-15 13:01' ), 'Sat 2026-08-22 13:00' );
check( 'published Sunday', cutoff( '2026-08-16 10:00' ), 'Sat 2026-08-22 13:00' );
check( 'published Monday', cutoff( '2026-08-17 08:00' ), 'Sat 2026-08-22 13:00' );

echo "\nA menu published Tuesday 11 August at 14:30\n";

$opened = '2026-08-11 14:30:00';

$cases = array(
	array( '2026-08-11 14:30', PCLM_Schedule::OPEN, 'the instant it is published' ),
	array( '2026-08-11 14:31', PCLM_Schedule::OPEN, 'a minute later' ),
	array( '2026-08-13 20:00', PCLM_Schedule::OPEN, 'Thursday evening' ),
	array( '2026-08-15 12:59', PCLM_Schedule::OPEN, 'Sat one minute early' ),
	array( '2026-08-15 13:00', PCLM_Schedule::CLOSED, 'Sat exactly at cutoff' ),
	array( '2026-08-15 13:05', PCLM_Schedule::CLOSED, 'Sat five past cutoff' ),
	array( '2026-08-16 09:00', PCLM_Schedule::CLOSED, 'Sunday during service' ),
	array( '2026-08-16 23:59', PCLM_Schedule::CLOSED, 'Sunday last minute' ),
	array( '2026-08-17 00:00', PCLM_Schedule::PAST, 'Monday after' ),
	array( '2026-08-11 09:00', PCLM_Schedule::CLOSED, 'before it was published' ),
);

foreach ( $cases as $case ) {
	list( $when, $expected, $label ) = $case;

	check( $label . ' (' . $when . ')', PCLM_Schedule::state_for( $opened, at( $when ) ), $expected );
}

echo "\nPublishing again reopens ordering\n";

// Sunday afternoon, the old menu has closed. A new menu goes up.
check( 'old menu still closed on Sunday', PCLM_Schedule::state_for( $opened, at( '2026-08-16 15:00' ) ), PCLM_Schedule::CLOSED );
check( 'new menu open immediately', PCLM_Schedule::state_for( '2026-08-16 15:00:00', at( '2026-08-16 15:00' ) ), PCLM_Schedule::OPEN );
check( 'new menu runs to the next Saturday', cutoff( '2026-08-16 15:00' ), 'Sat 2026-08-22 13:00' );

echo "\nCycles and service dates\n";

check( 'cycle key is the cutoff date', PCLM_Schedule::cycle_for( at( '2026-08-11 14:30' ) ), '2026-08-15' );
check( 'same cycle for a later publish in the same window', PCLM_Schedule::cycle_for( at( '2026-08-14 20:00' ) ), '2026-08-15' );
check( 'service date is the day after cutoff', PCLM_Schedule::service_date_for_cycle( '2026-08-15' ), '2026-08-16' );
check( 'cycle state open before cutoff', PCLM_Schedule::cycle_state( '2026-08-15', at( '2026-08-14 10:00' ) ), PCLM_Schedule::OPEN );
check( 'cycle state closed after cutoff', PCLM_Schedule::cycle_state( '2026-08-15', at( '2026-08-15 18:00' ) ), PCLM_Schedule::CLOSED );
check( 'cycle state past after service day', PCLM_Schedule::cycle_state( '2026-08-15', at( '2026-08-17 01:00' ) ), PCLM_Schedule::PAST );

echo "\nBad data fails closed rather than becoming buyable\n";

check( 'empty stamp is PAST', PCLM_Schedule::state_for( '', at( '2026-08-12 10:00' ) ), PCLM_Schedule::PAST );
check( 'garbage stamp is PAST', PCLM_Schedule::state_for( 'yesterday', at( '2026-08-12 10:00' ) ), PCLM_Schedule::PAST );
check( 'is_orderable false on bad stamp', PCLM_Schedule::is_orderable( 'nope', at( '2026-08-12 10:00' ) ), false );
check( 'unknown cycle is PAST', PCLM_Schedule::cycle_state( 'not-a-date', at( '2026-08-12 10:00' ) ), PCLM_Schedule::PAST );

echo "\nA different cutoff day still works\n";

PCLM_Settings::$overrides = array(
	'close_weekday' => 4,
	'close_time'    => '18:00',
);

check( 'Thursday 18:00 cutoff from Monday', cutoff( '2026-08-10 09:00' ), 'Thu 2026-08-13 18:00' );
check( 'Thursday 18:00 cutoff from Thursday evening', cutoff( '2026-08-13 19:00' ), 'Thu 2026-08-20 18:00' );

PCLM_Settings::$overrides = array();

echo "\nDaylight saving boundary (clocks go back Sunday 1 November 2026)\n";

check( 'published Tuesday before the change', cutoff( '2026-10-27 14:00' ), 'Sat 2026-10-31 13:00' );
check( 'still open Saturday 12:59', PCLM_Schedule::state_for( '2026-10-27 14:00:00', at( '2026-10-31 12:59' ) ), PCLM_Schedule::OPEN );
check( 'closed on Sunday after the clocks change', PCLM_Schedule::state_for( '2026-10-27 14:00:00', at( '2026-11-01 09:00' ) ), PCLM_Schedule::CLOSED );
check( 'service date spans the change', PCLM_Schedule::service_date_for_cycle( '2026-10-31' ), '2026-11-01' );

echo "\n$pass passed, $fail failed\n";

exit( $fail > 0 ? 1 : 0 );
