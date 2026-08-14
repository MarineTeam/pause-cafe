<?php

namespace PauseCafe;

use PauseCafe\SignIn\Auth0Method;
use PauseCafe\SignIn\MagicLinkMethod;
use PauseCafe\SignIn\Method;
use PauseCafe\SignIn\PasswordMethod;
use PauseCafe\SignIn\SupabaseMethod;

/**
 * The register of ways to sign in.
 *
 * The login page does not know what a password is, or what OpenID Connect is.
 * It asks which methods are on and renders what each one says it needs. Adding
 * a fifth way to sign in is a class and one register() call.
 *
 * Several can run at once, the way payment methods do — a church moving to
 * Auth0 can leave passwords on for the people who have not moved yet.
 *
 * Two rules keep this from locking anyone out of their own site:
 *
 *   1. available() never returns nothing. If every method is off or
 *      misconfigured, the password falls back on.
 *   2. Organisers can be allowed a password regardless of what members use,
 *      so a mistyped client secret costs a sign-in, not the site.
 */
class SignIn {

	/** @var array<string,Method> */
	private static array $methods = array();

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::register( new PasswordMethod() );
		self::register( new MagicLinkMethod() );
		self::register( new Auth0Method() );
		self::register( new SupabaseMethod() );
	}

	public static function register( Method $method ): void {
		self::$methods[ $method->id() ] = $method;
	}

	/**
	 * @return array<string,Method> Every registered method, on or off.
	 */
	public static function all(): array {
		self::boot();

		return self::$methods;
	}

	public static function get( string $id ): ?Method {
		return self::all()[ $id ] ?? null;
	}

	public static function settingKey( string $id ): string {
		return 'signin_' . $id . '_enabled';
	}

	public static function isEnabled( string $id ): bool {
		$method = self::get( $id );

		if ( ! $method ) {
			return false;
		}

		return 'yes' === Settings::get(
			self::settingKey( $id ),
			$method->enabledByDefault() ? 'yes' : 'no'
		);
	}

	/**
	 * Switched on, whether or not it can actually work.
	 *
	 * @return array<string,Method>
	 */
	public static function enabled(): array {
		return array_filter( self::all(), static fn( Method $m ) => self::isEnabled( $m->id() ) );
	}

	/**
	 * Switched on and able to work — what the login page should offer.
	 *
	 * An identity provider with no client secret is enabled but useless, and
	 * showing its button would only produce an error page. If that leaves
	 * nothing at all, the password comes back: better an unexpected password
	 * box than a site nobody can get into.
	 *
	 * @return array<string,Method>
	 */
	public static function available(): array {
		$usable = array_filter( self::enabled(), static fn( Method $m ) => $m->isConfigured() );

		if ( $usable ) {
			return $usable;
		}

		$password = self::get( 'password' );

		return $password ? array( $password->id() => $password ) : array();
	}

	public static function isAvailable( string $id ): bool {
		return isset( self::available()[ $id ] );
	}

	/**
	 * Whether organisers may always sign in with a password, no matter which
	 * methods members use. On unless deliberately turned off.
	 */
	public static function rescueAllowed(): bool {
		return 'no' !== Settings::get( 'signin_admin_rescue', 'yes' );
	}

	/**
	 * Whether the password rescue is worth offering — it is not, if passwords
	 * are already on the login page for everyone.
	 */
	public static function rescueOffered(): bool {
		return self::rescueAllowed() && ! self::isAvailable( 'password' );
	}

	/**
	 * The methods an organiser could use to get back in.
	 *
	 * @return string[] Method labels.
	 */
	public static function organiserRoutes(): array {
		$routes = array();

		foreach ( self::available() as $method ) {
			$routes[] = $method->label();
		}

		if ( self::rescueAllowed() && ! isset( self::available()['password'] ) ) {
			$routes[] = 'Organiser password';
		}

		return $routes;
	}

	/**
	 * Whether the password rescue can safely be switched off.
	 *
	 * Only once an organiser has genuinely signed in through a provider. An
	 * identity provider that is merely filled in is not a way in — a client
	 * secret with a typo in it is filled in, and the first time anybody finds
	 * out is when they try to sign in and cannot.
	 *
	 * So the old door stays until somebody has walked through the new one.
	 */
	public static function rescueMayBeDisabled(): bool {
		return Identities::provenForAdmin();
	}

	/**
	 * Why the rescue cannot be switched off yet, for the settings screen.
	 */
	public static function rescueLockReason(): string {
		return 'No organiser has signed in through a provider yet, so this is still the only '
			. 'way in that is known to work. Sign in with one — in another browser, so you do '
			. 'not lock this one out — and then it can be turned off.';
	}

	public static function label( string $id ): string {
		$method = self::get( $id );

		return $method ? $method->label() : ucfirst( $id );
	}

	/**
	 * @throws \RuntimeException When it does not exist or is not usable.
	 */
	public static function resolve( string $id ): Method {
		$method = self::get( $id );

		if ( ! $method ) {
			throw new \RuntimeException( 'That way of signing in does not exist.' );
		}

		if ( ! self::isEnabled( $id ) ) {
			throw new \RuntimeException( $method->label() . ' is switched off.' );
		}

		if ( ! $method->isConfigured() ) {
			throw new \RuntimeException( $method->label() . ' is not set up yet.' );
		}

		return $method;
	}

	/** Test seam: drops the register so a test can install its own methods. */
	public static function reset(): void {
		self::$methods = array();
		self::$booted  = false;
	}
}
