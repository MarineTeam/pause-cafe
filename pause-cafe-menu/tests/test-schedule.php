<?php
/**
 * Standalone check of the ordering-window rules.
 *
 * PCM_Schedule decides when every dish can be seen and bought, so it is worth
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

class PCM_Settings {

	public static $overrides = array();

	public static function get( $key ) {
		$defaults = array(
			'service_weekday'   => 0,
			'open_days_before'  => 5,
			'open_time'         => '12:00',
			'close_days_before' => 1,
			'close_time'        => '13:00',
		);

		$all = array_merge( $defaults, self::$overrides );

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function preview_upcoming() {
		return ! empty( self::$overrides['preview_upcoming'] );
	}
}

class PCM_Product {
	const META = '_pcm_service_date';
}

require __DIR__ . '/../includes/class-pcm-schedule.php';

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

$sunday = '2026-08-16';

echo "\nOrdering window around Sunday $sunday (opens Tue 12:00, closes Sat 13:00)\n";

check( 'opens at', PCM_Schedule::opens_at( $sunday )->format( 'D Y-m-d H:i' ), 'Tue 2026-08-11 12:00' );
check( 'closes at', PCM_Schedule::closes_at( $sunday )->format( 'D Y-m-d H:i' ), 'Sat 2026-08-15 13:00' );

$cases = array(
	array( '2026-08-10 09:00', PCM_Schedule::UPCOMING, 'Monday before' ),
	array( '2026-08-11 11:59', PCM_Schedule::UPCOMING, 'Tue one minute early' ),
	array( '2026-08-11 12:00', PCM_Schedule::OPEN, 'Tue exactly at open' ),
	array( '2026-08-14 18:00', PCM_Schedule::OPEN, 'Friday evening' ),
	array( '2026-08-15 12:59', PCM_Schedule::OPEN, 'Sat one minute early' ),
	array( '2026-08-15 13:00', PCM_Schedule::CLOSED, 'Sat exactly at cutoff' ),
	array( '2026-08-15 13:05', PCM_Schedule::CLOSED, 'Sat five past cutoff' ),
	array( '2026-08-16 10:00', PCM_Schedule::CLOSED, 'Sunday during service' ),
	array( '2026-08-16 23:59', PCM_Schedule::CLOSED, 'Sunday last minute' ),
	array( '2026-08-17 00:00', PCM_Schedule::PAST, 'Monday after' ),
);

foreach ( $cases as $case ) {
	list( $when, $expected, $label ) = $case;

	check( $label . ' (' . $when . ')', PCM_Schedule::state_for( $sunday, at( $when ) ), $expected );
}

echo "\nBad data fails closed rather than becoming buyable\n";

check( 'empty date is PAST', PCM_Schedule::state_for( '', at( '2026-08-12 10:00' ) ), PCM_Schedule::PAST );
check( 'garbage date is PAST', PCM_Schedule::state_for( 'next tuesday', at( '2026-08-12 10:00' ) ), PCM_Schedule::PAST );
check( 'Feb 31 is PAST', PCM_Schedule::state_for( '2026-02-31', at( '2026-02-01 10:00' ) ), PCM_Schedule::PAST );
check( 'is_orderable false on bad date', PCM_Schedule::is_orderable( 'nope', at( '2026-08-12 10:00' ) ), false );

echo "\nService days in a month\n";

check(
	'Sundays in August 2026',
	PCM_Schedule::service_dates_in_month( 2026, 8 ),
	array( '2026-08-02', '2026-08-09', '2026-08-16', '2026-08-23', '2026-08-30' )
);

check(
	'Sundays in February 2026',
	PCM_Schedule::service_dates_in_month( 2026, 2 ),
	array( '2026-02-01', '2026-02-08', '2026-02-15', '2026-02-22' )
);

PCM_Settings::$overrides['service_weekday'] = 6;

check(
	'Saturdays in August 2026',
	PCM_Schedule::service_dates_in_month( 2026, 8 ),
	array( '2026-08-01', '2026-08-08', '2026-08-15', '2026-08-22', '2026-08-29' )
);

PCM_Settings::$overrides = array();

echo "\nDaylight saving boundary (clocks go back Sunday 1 November 2026)\n";

$dst = '2026-11-01';

check( 'opens Tue before the change', PCM_Schedule::opens_at( $dst )->format( 'D Y-m-d H:i' ), 'Tue 2026-10-27 12:00' );
check( 'closes Sat at 13:00 local', PCM_Schedule::closes_at( $dst )->format( 'D Y-m-d H:i' ), 'Sat 2026-10-31 13:00' );
check( 'still open Sat 12:59', PCM_Schedule::state_for( $dst, at( '2026-10-31 12:59' ) ), PCM_Schedule::OPEN );
check( 'closed Sun after clock change', PCM_Schedule::state_for( $dst, at( '2026-11-01 09:00' ) ), PCM_Schedule::CLOSED );

echo "\n$pass passed, $fail failed\n";

exit( $fail > 0 ? 1 : 0 );
