<?php
/**
 * Dishes that do not fit the weekly rhythm.
 *
 * Two holes, both reported from real use. The single-dish editor had no
 * schedule field, so every dish added that way landed on the default schedule
 * and the only way to give one its own timing was to change the schedule
 * everything else used. And there was no way at all to sell a one-off -- a
 * Christmas menu, a box of chocolates, something wanting a fortnight's notice.
 * Trying to fake one by giving it a Wednesday service date pushed a Wednesday
 * section onto the front page and hid the Sunday menu behind it.
 *
 * So what these tests really guard is containment: a standalone dish has to be
 * orderable without touching any week.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-menu-flexibility.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Menu;
use PauseCafe\Schedule;
use PauseCafe\Schedules;

Schedule::freeze( new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

/* ---------------------------------------------------------------------------
 * A dish can be put on a schedule other than the default.
 * ------------------------------------------------------------------------ */

$wednesdays = Schedules::save(
	array(
		'name'              => 'Wednesdays',
		'mode'              => Schedule::MODE_PLANNED,
		'open_days_before'  => 7,
		'close_days_before' => 2,
		'open_time'         => '09:00',
		'close_time'        => '13:00',
	)
);

check( 'a second schedule can be created', $wednesdays > Schedules::DEFAULT_ID, true );

$sunday = Menu::save(
	array(
		'location_id'  => 1,
		'name'         => 'Sunday roast',
		'price_cents'  => 1200,
		'service_date' => '2026-09-06',
		'status'       => 'published',
	)
);

$midweek = Menu::save(
	array(
		'location_id'  => 1,
		'name'         => 'Midweek curry',
		'price_cents'  => 1000,
		'service_date' => '2026-09-09',
		'status'       => 'published',
		'schedule_id'  => $wednesdays,
	)
);

check( 'no schedule stores as NULL, the settings-backed default', Menu::item( $sunday )['schedule_id'], null );
check( 'a chosen schedule is stored against the dish', (int) Menu::item( $midweek )['schedule_id'], $wednesdays );

/*
 * The point of the field: each dish resolves against its own rules, and giving
 * one its own timing no longer means editing the schedule every other dish is
 * on.
 */
check( 'the default dish keeps its own service date', Menu::item( $sunday )['window']->serviceDate, '2026-09-06' );
check( 'the second schedule resolves its own service date', Menu::item( $midweek )['window']->serviceDate, '2026-09-09' );

check( 'each schedule sees only its own dates', Menu::serviceDates( Schedules::DEFAULT_ID ), array( '2026-09-06' ) );
check( 'and the second schedule sees only its own', Menu::serviceDates( $wednesdays ), array( '2026-09-09' ) );

/* ---------------------------------------------------------------------------
 * Standalone dishes: orderable, and contained.
 * ------------------------------------------------------------------------ */

$chocolates = Menu::save(
	array(
		'location_id' => 1,
		'name'        => 'Box of chocolates',
		'price_cents' => 1500,
		'status'      => 'published',
		'standalone'  => 1,
		// Its own window is the whole schedule a one-off gets.
		'open_from'   => '2026-09-01 00:00',
		'close_at'    => '2026-09-30 23:59',
	)
);

$item = Menu::item( $chocolates );

check( 'the standalone flag is stored', (int) $item['standalone'], 1 );
check( 'a standalone dish inside its own window is orderable', $item['window']->isOrderable(), true );
check( 'standaloneItems returns it', array_column( Menu::standaloneItems(), 'name' ), array( 'Box of chocolates' ) );

// Containment. This is the reported bug: a one-off must not create a week.
check(
	'a standalone dish never appears in a weekly section',
	in_array( $chocolates, array_column( Menu::itemsForServiceDate( (string) $item['window']->serviceDate ), 'id' ), true ),
	false
);
check(
	'and never adds a date to the front page',
	Menu::serviceDates( Schedules::DEFAULT_ID ),
	array( '2026-09-06' )
);
check(
	'and never occupies a cell in the month builder',
	Menu::itemBySlot( (string) $item['window']->serviceDate, 1 ),
	null
);

// It is still a dish: the organiser has to be able to find it.
check(
	'a standalone dish is still listed on the admin menu screen',
	in_array( $chocolates, array_column( Menu::allItems(), 'id' ), true ),
	true
);

/* ---------------------------------------------------------------------------
 * A long lead time, which is the other thing one-offs are for.
 * ------------------------------------------------------------------------ */

$xmas = Menu::save(
	array(
		'location_id' => 1,
		'name'        => 'Christmas menu',
		'price_cents' => 4000,
		'status'      => 'published',
		'standalone'  => 1,
		'open_from'   => '2026-09-01 00:00',
		'close_at'    => '2026-12-10 13:00',
	)
);

check( 'a window three months long is orderable throughout', Menu::item( $xmas )['window']->isOrderable(), true );

Schedule::freeze( new DateTimeImmutable( '2026-12-20 09:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

check( 'and closes on its own cutoff, not the weekly one', Menu::item( $xmas )['window']->isOrderable(), false );
check( 'a closed one-off drops out of the section', Menu::standaloneItems( false ), array() );

Schedule::freeze( new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

/* ---------------------------------------------------------------------------
 * Drafting still works the same way, because it is the same status column.
 * ------------------------------------------------------------------------ */

Menu::save(
	array(
		'location_id' => 1,
		'name'        => 'Box of chocolates',
		'price_cents' => 1500,
		'status'      => 'draft',
		'standalone'  => 1,
		'open_from'   => '2026-09-01 00:00',
		'close_at'    => '2026-09-30 23:59',
	),
	$chocolates
);

check(
	'an unpublished one-off is not offered',
	in_array( 'Box of chocolates', array_column( Menu::standaloneItems(), 'name' ), true ),
	false
);

finish();
