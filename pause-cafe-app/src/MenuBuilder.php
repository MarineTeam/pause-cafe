<?php

namespace PauseCafe;

/**
 * The grid way of entering a menu: dates down, pickup locations across.
 *
 * Sits alongside the one-dish-at-a-time editor rather than replacing it. The
 * grid is for filling a month in one sitting; the editor is for a price, a
 * photo or a portion limit on a single dish. Both write the same rows.
 */
class MenuBuilder {

	/**
	 * Creates, updates or drafts the dish in one cell.
	 *
	 * @param array   $extra       Fields to force, such as a manual window or an
	 *                             on-publish stamp.
	 * @param ?string $forceLookup Service date to match on when the cell carries
	 *                             none, as in on-publish mode.
	 *
	 * @return string created, updated, drafted, or '' when nothing changed.
	 */
	public static function saveSlot(
		string $serviceDate,
		int $locationId,
		string $name,
		array $extra = array(),
		?string $forceLookup = null,
		$scheduleId = Schedules::DEFAULT_ID
	): string {
		if ( ! $locationId ) {
			return '';
		}

		$name     = trim( $name );
		$lookup   = '' !== $serviceDate ? $serviceDate : (string) $forceLookup;
		$existing = '' !== $lookup ? Menu::itemBySlot( $lookup, $locationId, $scheduleId ) : null;

		if ( '' === $name ) {
			/*
			 * Clearing a cell drafts the dish rather than deleting it. Anything
			 * ordered against it has to keep pointing somewhere.
			 */
			if ( $existing && 'draft' !== $existing['status'] ) {
				Menu::save( array_merge( $existing, array( 'status' => 'draft' ) ), (int) $existing['id'] );

				return 'drafted';
			}

			return '';
		}

		if ( $existing ) {
			if ( self::unchanged( $existing, $name, $extra ) ) {
				return '';
			}

			$changes = array_merge( $existing, $extra, array( 'name' => $name, 'status' => 'published' ) );

			if ( '' !== $serviceDate ) {
				$changes['service_date'] = $serviceDate;
			}

			Menu::save( $changes, (int) $existing['id'] );

			return 'updated';
		}

		// A repeat inherits its price and description from the last dish of the
		// same name, so nothing has to be priced twice.
		$template = Menu::templateFor( $name );

		Menu::save(
			array_merge(
				array(
					'location_id'  => $locationId,
					'name'         => $name,
					'description'  => $template ? (string) $template['description'] : '',
					'price_cents'  => $template
						? (int) $template['price_cents']
						: Money::parse( Settings::get( 'default_price', '10.00' ) ),
					'service_date' => $serviceDate,
					'capacity'     => 0,
					'status'       => 'published',
					'schedule_id'  => (int) $scheduleId,
				),
				$extra
			)
		);

		return 'created';
	}

	private static function unchanged( array $existing, string $name, array $extra ): bool {
		/*
		 * A drafted dish never counts as unchanged, so submitting its name
		 * republishes it. That is deliberate — typing a name back into a cell
		 * you cleared is how you put it back — and it is safe only because the
		 * builder does not put an unpublished dish's name in the box in the
		 * first place. It shows it as a placeholder instead, which is visible
		 * but not submitted.
		 *
		 * If that ever changes, this line silently republishes every drafted
		 * dish in the month the next time anybody saves the grid.
		 */
		if ( $existing['name'] !== $name || 'published' !== $existing['status'] ) {
			return false;
		}

		// An on-publish stamp always counts as a change: saving that row is how
		// ordering is reopened.
		if ( isset( $extra['opened_at'] ) ) {
			return false;
		}

		foreach ( array( 'open_from', 'close_at' ) as $key ) {
			if ( isset( $extra[ $key ] ) && (string) $existing[ $key ] !== (string) $extra[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Saves a whole submitted grid.
	 *
	 * @param array $dishes In on-publish mode, location => name. Otherwise
	 *                      date => location => name.
	 *
	 * @return array{created:int,updated:int,drafted:int}
	 */
	public static function save( array $dishes, array $froms = array(), array $untils = array(), $scheduleId = Schedules::DEFAULT_ID ): array {
		$rules = Schedules::rulesFor( $scheduleId );
		$mode  = (string) $rules['mode'];
		$today = Schedule::now()->format( 'Y-m-d' );
		$tally = array(
			'created' => 0,
			'updated' => 0,
			'drafted' => 0,
		);

		$count = static function ( string $outcome ) use ( &$tally ): void {
			if ( '' !== $outcome ) {
				++$tally[ $outcome ];
			}
		};

		if ( Schedule::MODE_ON_PUBLISH === $mode ) {
			$now     = Schedule::now()->format( 'Y-m-d H:i:s' );
			$current = Menu::currentServiceDate( $scheduleId );

			foreach ( $dishes as $locationId => $name ) {
				$count(
					self::saveSlot(
						'',
						(int) $locationId,
						(string) $name,
						array( 'opened_at' => $now ),
						$current,
						$scheduleId
					)
				);
			}

			return $tally;
		}

		foreach ( $dishes as $serviceDate => $slots ) {
			$serviceDate = (string) $serviceDate;

			// Weeks already served are shown read-only and never rewritten.
			if ( ! Schedule::parseDate( $serviceDate ) || $serviceDate < $today ) {
				continue;
			}

			$extra = array();

			if ( Schedule::MODE_MANUAL === $mode ) {
				$extra['open_from'] = trim( (string) ( $froms[ $serviceDate ] ?? '' ) );
				$extra['close_at']  = trim( (string) ( $untils[ $serviceDate ] ?? '' ) );
			}

			foreach ( (array) $slots as $locationId => $name ) {
				$count( self::saveSlot( $serviceDate, (int) $locationId, (string) $name, $extra, null, $scheduleId ) );
			}
		}

		return $tally;
	}
}
