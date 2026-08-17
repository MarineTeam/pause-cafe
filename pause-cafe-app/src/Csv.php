<?php

namespace PauseCafe;

/**
 * Writing CSV that a spreadsheet will not run.
 *
 * Quoting protects the shape of a CSV file. It does nothing about what Excel
 * and LibreOffice do when a cell begins with =, +, - or @: they treat it as a
 * formula and evaluate it on open. Half of what the kitchen list exports was
 * typed by a member -- the name on a meal, the note against it, the group --
 * so somebody could order lunch under the name
 *
 *     =HYPERLINK("https://elsewhere.example","Open me")
 *
 * and wait for an organiser to open the spreadsheet. The nastier payloads reach
 * further than a link; older Excel had DDE, and there is no reason to find out
 * which build the organiser is running.
 *
 * The fix is an apostrophe in front, which those programs read as "this cell is
 * text" and do not display. It has to happen before fputcsv(), because the
 * escaping fputcsv() does is about commas and quotes, not about arithmetic.
 */
class Csv {

	/**
	 * Characters a spreadsheet may read as the start of an expression.
	 *
	 * Tab and carriage return are here because a cell can begin with one and
	 * still have a formula behind it -- the leading whitespace is swallowed and
	 * what follows is evaluated.
	 */
	private const TRIGGERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Writes one row, with every cell made inert first.
	 *
	 * Neutralising happens here rather than at the call sites on purpose. There
	 * are four exports across this app and its plugins, each with a dozen
	 * columns, and a rule applied a column at a time is a rule that will be
	 * missed the next time somebody adds one.
	 *
	 * $escape must be passed explicitly: PHP 8.4 deprecates relying on its
	 * default, and the notice is emitted straight into this stream, which
	 * corrupts the downloaded file. Empty string is both what PHP 9 defaults to
	 * and the RFC 4180 behaviour.
	 *
	 * @param resource $handle
	 * @param array    $fields
	 */
	public static function write( $handle, array $fields ): void {
		fputcsv( $handle, array_map( array( self::class, 'cell' ), $fields ), ',', '"', '' );
	}

	/**
	 * One cell, safe to open.
	 *
	 * Numbers are deliberately left alone. Prefixing them would turn a column
	 * of money into a column of text, and the organiser adding up what is owed
	 * would get nothing -- so a refund of -12.50 stays a number a spreadsheet
	 * can total, while -12.50+SUM(A:A) does not. What separates them is whether
	 * the whole value is a number, not what it starts with.
	 */
	public static function cell( $value ): string {
		$text = (string) $value;

		if ( '' === $text || ! in_array( $text[0], self::TRIGGERS, true ) ) {
			return $text;
		}

		if ( self::isPlainNumber( $text ) ) {
			return $text;
		}

		return "'" . $text;
	}

	/**
	 * Whether this is a number and nothing else -- including the shapes money
	 * is written in here, which carry a currency symbol and thousands commas.
	 */
	private static function isPlainNumber( string $text ): bool {
		if ( is_numeric( $text ) ) {
			return true;
		}

		return 1 === preg_match( '/^[-+]?[$£€]?\d{1,3}(,\d{3})*(\.\d+)?%?$/', $text );
	}
}
