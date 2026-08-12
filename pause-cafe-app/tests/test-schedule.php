<?php
/**
 * Window resolution across all three modes.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-schedule.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Blackouts;
use PauseCafe\Schedule;
use PauseCafe\Settings;
use PauseCafe\Window;

function at( string $when ): DateTimeImmutable {
	return new DateTimeImmutable( $when, Schedule::timezone() );
}

/**
 * Builds a menu_items-shaped array without touching the database.
 */
function item( array $overrides = array() ): array {
	return array_merge(
		array(
			'id'           => 1,
			'service_date' => '',
			'opened_at'    => '',
			'open_from'    => '',
			'close_at'     => '',
		),
		$overrides
	);
}

function moment( Window $w, string $which ): ?string {
	return $w->$which?->format( 'D Y-m-d H:i' );
}

/* ------------------------------------------------------------------ */

echo "\nPlanned mode: the service date drives everything\n";

Settings::setMany(
	array(
		'active_mode'       => Schedule::MODE_PLANNED,
		'service_weekday'   => '0',
		'open_days_before'  => '5',
		'open_time'         => '12:00',
		'close_days_before' => '1',
		'close_time'        => '13:00',
	)
);

$w = Schedule::forItem( item( array( 'service_date' => '2026-08-16' ) ) );

check( 'source', $w->source, Schedule::MODE_PLANNED );
check( 'opens Tuesday noon', moment( $w, 'openFrom' ), 'Tue 2026-08-11 12:00' );
check( 'closes Saturday 1pm', moment( $w, 'closeAt' ), 'Sat 2026-08-15 13:00' );
check( 'service date', $w->serviceDate, '2026-08-16' );

foreach (
	array(
		array( '2026-08-10 09:00', Window::UPCOMING, 'Monday before' ),
		array( '2026-08-11 11:59', Window::UPCOMING, 'one minute early' ),
		array( '2026-08-11 12:00', Window::OPEN, 'exactly at open' ),
		array( '2026-08-15 12:59', Window::OPEN, 'one minute before cutoff' ),
		array( '2026-08-15 13:00', Window::CLOSED, 'exactly at cutoff' ),
		array( '2026-08-16 23:59', Window::CLOSED, 'end of the service day' ),
		array( '2026-08-17 00:00', Window::PAST, 'the day after' ),
	) as $case
) {
	list( $when, $expected, $label ) = $case;

	check( $label . ' (' . $when . ')', $w->state( at( $when ) ), $expected );
}

check( 'no date at all is NONE', Schedule::forItem( item() )->state( at( '2026-08-12 10:00' ) ), Window::NONE );

/* ------------------------------------------------------------------ */

echo "\nOn-publish mode: the moment of publishing drives everything\n";

Settings::setMany(
	array(
		'active_mode'              => Schedule::MODE_ON_PUBLISH,
		'close_weekday'            => '6',
		'close_time'               => '13:00',
		'service_days_after_close' => '1',
	)
);

$w = Schedule::forItem( item( array( 'opened_at' => '2026-08-11 14:30:00' ) ) );

check( 'source', $w->source, Schedule::MODE_ON_PUBLISH );
check( 'opens at the publish moment', moment( $w, 'openFrom' ), 'Tue 2026-08-11 14:30' );
check( 'closes next Saturday 1pm', moment( $w, 'closeAt' ), 'Sat 2026-08-15 13:00' );
check( 'service date derived', $w->serviceDate, '2026-08-16' );
check( 'open right after publishing', $w->state( at( '2026-08-11 14:31' ) ), Window::OPEN );

$w = Schedule::forItem( item( array( 'opened_at' => '2026-08-15 13:00:00' ) ) );
check( 'published exactly at cutoff rolls forward', moment( $w, 'closeAt' ), 'Sat 2026-08-22 13:00' );

$w = Schedule::forItem( item( array( 'opened_at' => '2026-08-15 14:00:00' ) ) );
check( 'published after cutoff rolls forward', moment( $w, 'closeAt' ), 'Sat 2026-08-22 13:00' );

$w = Schedule::forItem( item( array( 'opened_at' => '2026-08-16 15:00:00' ) ) );
check( 'republishing on Sunday reopens', $w->state( at( '2026-08-16 15:30' ) ), Window::OPEN );

check( 'never published is NONE', Schedule::forItem( item() )->state( at( '2026-08-12 10:00' ) ), Window::NONE );

/* ------------------------------------------------------------------ */

echo "\nManual mode: the dish carries its own window\n";

Settings::set( 'active_mode', Schedule::MODE_MANUAL );

$w = Schedule::forItem(
	item(
		array(
			'open_from' => '2026-08-10 08:00:00',
			'close_at'  => '2026-08-14 17:00:00',
		)
	)
);

check( 'source', $w->source, Schedule::MODE_MANUAL );
check( 'opens when told', moment( $w, 'openFrom' ), 'Mon 2026-08-10 08:00' );
check( 'closes when told', moment( $w, 'closeAt' ), 'Fri 2026-08-14 17:00' );
check( 'service date derived from the close', $w->serviceDate, '2026-08-15' );
check( 'open inside the window', $w->state( at( '2026-08-12 12:00' ) ), Window::OPEN );

check(
	'from without until is NONE',
	Schedule::forItem( item( array( 'open_from' => '2026-08-10 08:00:00' ) ) )->state( at( '2026-08-12 10:00' ) ),
	Window::NONE
);

check(
	'until before from is NONE',
	Schedule::forItem(
		item(
			array(
				'open_from' => '2026-08-14 17:00:00',
				'close_at'  => '2026-08-10 08:00:00',
			)
		)
	)->state( at( '2026-08-12 10:00' ) ),
	Window::NONE
);

check(
	'an explicit served date wins over the derived one',
	Schedule::forItem(
		item(
			array(
				'open_from'    => '2026-08-10 08:00:00',
				'close_at'     => '2026-08-14 17:00:00',
				'service_date' => '2026-08-20',
			)
		)
	)->serviceDate,
	'2026-08-20'
);

/* ------------------------------------------------------------------ */

echo "\nA per-dish window overrides whatever mode is active\n";

Settings::set( 'active_mode', Schedule::MODE_PLANNED );

$w = Schedule::forItem(
	item(
		array(
			'service_date' => '2026-08-16',
			'open_from'    => '2026-08-09 08:00:00',
			'close_at'     => '2026-08-13 20:00:00',
		)
	)
);

check( 'source is override', $w->source, 'override' );
check( 'the override supplies the close', moment( $w, 'closeAt' ), 'Thu 2026-08-13 20:00' );
check( 'the explicit served date still counts', $w->serviceDate, '2026-08-16' );
check( 'open when the planned window would not be', $w->state( at( '2026-08-10 09:00' ) ), Window::OPEN );
check( 'closed while the planned window would still be open', $w->state( at( '2026-08-14 09:00' ) ), Window::CLOSED );

/* ------------------------------------------------------------------ */

echo "\nBlackout dates void the window\n";

Blackouts::add( '2026-12-27', 'Christmas — no lunch' );

$w = Schedule::forItem( item( array( 'service_date' => '2026-12-27' ) ) );

check( 'source becomes blackout', $w->source, Window::BLACKOUT );
check( 'not orderable', $w->isOrderable( at( '2026-12-23 10:00' ) ), false );
check( 'not listed', $w->isListed( at( '2026-12-23 10:00' ) ), false );
check( 'the label is the message', $w->message(), 'Christmas — no lunch' );

$w = Schedule::forItem( item( array( 'service_date' => '2026-12-20' ) ) );
check( 'the week before is unaffected', $w->state( at( '2026-12-16 13:00' ) ), Window::OPEN );

Blackouts::remove( '2026-12-27' );

/* ------------------------------------------------------------------ */

echo "\nPreview controls whether an unopened week is listed\n";

Settings::set( 'preview_upcoming', 'no' );
$w = Schedule::forItem( item( array( 'service_date' => '2026-08-16' ) ) );
check( 'preview off: upcoming is hidden', $w->isListed( at( '2026-08-10 09:00' ) ), false );
check( 'preview off: open is listed', $w->isListed( at( '2026-08-12 09:00' ) ), true );
check( 'preview off: closed is still listed', $w->isListed( at( '2026-08-16 09:00' ) ), true );
check( 'preview off: past is hidden', $w->isListed( at( '2026-08-18 09:00' ) ), false );

Settings::set( 'preview_upcoming', 'yes' );
$w = Schedule::forItem( item( array( 'service_date' => '2026-08-16' ) ) );
check( 'preview on: upcoming is listed', $w->isListed( at( '2026-08-10 09:00' ) ), true );
Settings::set( 'preview_upcoming', 'no' );

/* ------------------------------------------------------------------ */

echo "\nBad data fails closed rather than becoming buyable\n";

check( 'garbage date is NONE', Schedule::forItem( item( array( 'service_date' => 'next sunday' ) ) )->state( at( '2026-08-12 10:00' ) ), Window::NONE );
check( 'Feb 31 is NONE', Schedule::forItem( item( array( 'service_date' => '2026-02-31' ) ) )->state( at( '2026-02-01 10:00' ) ), Window::NONE );
check( 'an empty item is not orderable', Schedule::forItem( item() )->isOrderable( at( '2026-08-12 10:00' ) ), false );

/* ------------------------------------------------------------------ */

echo "\nDaylight saving boundary (clocks go back Sunday 1 November 2026)\n";

$w = Schedule::forItem( item( array( 'service_date' => '2026-11-01' ) ) );

check( 'opens Tuesday before the change', moment( $w, 'openFrom' ), 'Tue 2026-10-27 12:00' );
check( 'closes Saturday 1pm local', moment( $w, 'closeAt' ), 'Sat 2026-10-31 13:00' );
check( 'still open Saturday 12:59', $w->state( at( '2026-10-31 12:59' ) ), Window::OPEN );
check( 'closed on Sunday after the clocks change', $w->state( at( '2026-11-01 09:00' ) ), Window::CLOSED );

finish();
