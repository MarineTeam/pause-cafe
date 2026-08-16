<?php

namespace PauseCafe;

/**
 * Sessions, sign-in, and the two gates: signed in, and allowed to order.
 */
class Auth {

	private static ?array $user = null;

	private static bool $loaded = false;

	public static function start( bool $https ): void {
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			return;
		}

		session_set_cookie_params(
			array(
				'lifetime' => 0,
				'path'     => '/',
				'httponly' => true,
				'secure'   => $https,
				'samesite' => 'Lax',
			)
		);

		session_name( 'pausecafe');
		session_start();
	}

	public static function login( array $user ): void {
		// Stops a session fixed before sign-in from carrying over afterwards.
		session_regenerate_id( true );

		$_SESSION['user_id'] = (int) $user['id'];

		self::$user   = $user;
		self::$loaded = true;
	}

	public static function logout(): void {
		$_SESSION = array();

		if ( ini_get( 'session.use_cookies' ) ) {
			$params = session_get_cookie_params();

			setcookie(
				session_name(),
				'',
				array(
					'expires'  => time() - 42000,
					'path'     => $params['path'],
					'httponly' => true,
					'secure'   => $params['secure'],
					'samesite' => 'Lax',
				)
			);
		}

		session_destroy();

		self::$user   = null;
		self::$loaded = true;
	}

	public static function user(): ?array {
		if ( self::$loaded ) {
			return self::$user;
		}

		self::$loaded = true;

		$id = (int) ( $_SESSION['user_id'] ?? 0 );

		if ( $id ) {
			self::$user = Users::find( $id );

			/*
			 * The account went away while the session was still alive -- either
			 * removed, or closed by an organiser. Closing has to take effect on
			 * the next request rather than whenever they next sign in, or an
			 * account closed at eleven is still ordering lunch at noon.
			 */
			if ( ! self::$user || Users::isDisabled( self::$user ) ) {
				self::$user = null;
				unset( $_SESSION['user_id'] );
			}
		}

		return self::$user;
	}

	public static function id(): int {
		$user = self::user();

		return $user ? (int) $user['id'] : 0;
	}

	public static function check(): bool {
		return null !== self::user();
	}

	public static function isAdmin(): bool {
		return Users::isAdmin( self::user() );
	}

	public static function canOrder(): bool {
		return Users::canOrder( self::user() );
	}

	/** Forgets the cached row so a change made this request is picked up. */
	public static function refresh(): void {
		self::$loaded = false;
		self::$user   = null;
	}
}
