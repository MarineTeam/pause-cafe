<?php

namespace PauseCafe;

/**
 * The cart, in the session.
 *
 * Lines are a plain list rather than keyed by dish, because the same dish
 * ordered twice for two different people is two lines, not a quantity of two.
 * The name and group on each line are what the servers read off the sheet on
 * Sunday, so they belong to the line and not to the order.
 */
class Cart {

	private const KEY = 'cart';

	/**
	 * @return array[] Each: item_id, qty, person_name, group_name.
	 */
	public static function lines(): array {
		return $_SESSION[ self::KEY ] ?? array();
	}

	private static function store( array $lines ): void {
		$_SESSION[ self::KEY ] = array_values( $lines );
	}

	/**
	 * Splits answers into the three that have their own columns and the rest.
	 *
	 * @param array<string,string> $values Already sanitised, keyed by field.
	 */
	private static function shape( int $itemId, int $qty, array $values ): array {
		$extra = $values;

		foreach ( MenuFields::BUILTINS as $key ) {
			unset( $extra[ $key ] );
		}

		return array(
			'item_id'     => $itemId,
			'qty'         => max( 1, $qty ),
			'person_name' => (string) ( $values[ MenuFields::PERSON ] ?? '' ),
			'group_name'  => (string) ( $values[ MenuFields::GROUP ] ?? '' ),
			'note'        => (string) ( $values[ MenuFields::NOTE ] ?? '' ),
			'extra'       => array_filter( $extra, static fn( $v ) => '' !== $v ),
		);
	}

	/**
	 * @param array<string,string> $values Keyed by field key.
	 */
	public static function add( int $itemId, int $qty, array $values ): void {
		$lines   = self::lines();
		$lines[] = self::shape( $itemId, $qty, $values );

		self::store( $lines );
	}

	/**
	 * @param array<string,string> $values Keyed by field key.
	 */
	public static function update( int $index, int $qty, array $values ): void {
		$lines = self::lines();

		if ( ! isset( $lines[ $index ] ) ) {
			return;
		}

		if ( $qty < 1 ) {
			unset( $lines[ $index ] );
			self::store( $lines );

			return;
		}

		$lines[ $index ] = self::shape( (int) $lines[ $index ]['item_id'], $qty, $values );

		self::store( $lines );
	}

	/**
	 * Breaks one line of quantity N into N lines of one.
	 *
	 * Two of the same dish is more often two children than one hungry adult,
	 * and a single line can only carry one name. Splitting gives each meal its
	 * own name, group and note -- which is what the kitchen reads off the sheet
	 * on Sunday. The copies keep the original's answers so a split is a
	 * starting point rather than a blank slate.
	 *
	 * @return int How many lines the one became.
	 */
	public static function split( int $index ): int {
		$lines = self::lines();

		if ( ! isset( $lines[ $index ] ) ) {
			return 0;
		}

		$line = $lines[ $index ];
		$qty  = max( 1, (int) $line['qty'] );

		if ( 1 === $qty ) {
			return 1;
		}

		$line['qty'] = 1;

		// Spliced in place, so the copies sit together where the original was
		// rather than jumping to the bottom of a long cart.
		array_splice( $lines, $index, 1, array_fill( 0, $qty, $line ) );

		self::store( $lines );

		return $qty;
	}

	public static function remove( int $index ): void {
		$lines = self::lines();

		unset( $lines[ $index ] );

		self::store( $lines );
	}

	public static function clear(): void {
		unset( $_SESSION[ self::KEY ] );
	}

	public static function count(): int {
		$total = 0;

		foreach ( self::lines() as $line ) {
			$total += (int) $line['qty'];
		}

		return $total;
	}

	/**
	 * Lines with their dish, window and subtotal attached, plus any problem that
	 * would stop checkout. Lines whose dish has vanished are dropped rather than
	 * left to blow up at checkout.
	 *
	 * @return array{lines: array[], total: int, problems: string[]}
	 */
	public static function detailed(): array {
		$lines    = array();
		$total    = 0;
		$problems = array();
		$dropped  = false;
		$raw      = self::lines();

		foreach ( $raw as $index => $line ) {
			$item = Menu::item( (int) $line['item_id'] );

			if ( ! $item || 'published' !== $item['status'] ) {
				unset( $raw[ $index ] );
				$dropped    = true;
				$problems[] = 'A dish in your cart is no longer on the menu and has been removed.';

				continue;
			}

			$qty      = max( 1, (int) $line['qty'] );
			$subtotal = $item['price_cents'] * $qty;
			$total   += $subtotal;

			if ( ! $item['window']->isOrderable() ) {
				$problems[] = $item['name'] . ' cannot be ordered right now. ' . $item['window']->message();
			} elseif ( null !== $item['remaining'] && $qty > $item['remaining'] ) {
				$problems[] = $item['name'] . ' only has ' . $item['remaining'] . ' left.';
			}

			$lines[] = array(
				'index'       => $index,
				'item'        => $item,
				'qty'         => $qty,
				'person_name' => (string) $line['person_name'],
				'group_name'  => (string) $line['group_name'],
				'note'        => (string) ( $line['note'] ?? '' ),
				'extra'       => (array) ( $line['extra'] ?? array() ),
				'subtotal'    => $subtotal,
			);
		}

		if ( $dropped ) {
			self::store( $raw );
		}

		// Everything in one order has to be for the same day.
		$dates = array();

		foreach ( $lines as $line ) {
			$date = $line['item']['window']->serviceDate;

			if ( '' !== $date ) {
				$dates[ $date ] = true;
			}
		}

		if ( count( $dates ) > 1 ) {
			$problems[] = 'Your cart has dishes for more than one day. Please order them separately.';
		}

		return array(
			'lines'       => $lines,
			'total'       => $total,
			'problems'    => array_values( array_unique( $problems ) ),
			'serviceDate' => $dates ? array_key_first( $dates ) : '',
		);
	}
}
