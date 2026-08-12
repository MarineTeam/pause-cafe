<?php

namespace PauseCafe;

/**
 * CSRF tokens.
 *
 * Every state-changing request carries one. Without this, a page on another
 * site could quietly place an order or move money using a signed-in member's
 * cookie.
 */
class Csrf {

	public static function token(): string {
		if ( empty( $_SESSION['csrf'] ) ) {
			$_SESSION['csrf'] = bin2hex( random_bytes( 32 ) );
		}

		return $_SESSION['csrf'];
	}

	public static function field(): string {
		return '<input type="hidden" name="_token" value="' . htmlspecialchars( self::token(), ENT_QUOTES ) . '">';
	}

	public static function valid( ?string $token ): bool {
		if ( empty( $_SESSION['csrf'] ) || ! is_string( $token ) || '' === $token ) {
			return false;
		}

		return hash_equals( $_SESSION['csrf'], $token );
	}

	/**
	 * @throws \RuntimeException When the token is missing or wrong.
	 */
	public static function verify(): void {
		if ( ! self::valid( $_POST['_token'] ?? null ) ) {
			throw new \RuntimeException( 'That form expired. Please try again.', 419 );
		}
	}
}
