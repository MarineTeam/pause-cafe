<?php

namespace PauseCafe;

/**
 * Money is integer cents everywhere -- in the database, in the cart, in the
 * ledger. Floats are only ever produced at the edges, for display and for
 * parsing what someone typed.
 */
class Money {

	private static string $symbol = '$';

	public static function configure( string $symbol ): void {
		self::$symbol = $symbol;
	}

	public static function format( int $cents ): string {
		$sign  = $cents < 0 ? '-' : '';
		$whole = intdiv( abs( $cents ), 100 );
		$part  = abs( $cents ) % 100;

		return $sign . self::$symbol . number_format( $whole ) . '.' . str_pad( (string) $part, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Parses a typed amount into cents. Accepts "10", "10.5", "$10.50", "1,000".
	 * Rounds rather than truncates so 10.005 does not silently lose a cent.
	 */
	public static function parse( string $input ): int {
		$clean = preg_replace( '/[^0-9.\-]/', '', $input );

		if ( '' === $clean || '-' === $clean || '.' === $clean ) {
			return 0;
		}

		return (int) round( ( (float) $clean ) * 100 );
	}
}
