<?php

namespace PauseCafe;

use PauseCafe\Payments\CodMethod;
use PauseCafe\Payments\Method;
use PauseCafe\Payments\WalletMethod;

/**
 * The register of payment methods.
 *
 * Ordering never names a method. It asks this class which are enabled, hands
 * the chosen one the order, and lets it decide what taking payment means. That
 * is what keeps the wallet optional and leaves room for another method later
 * without touching checkout.
 */
class Payments {

	/** @var array<string,Method> */
	private static array $methods = array();

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::register( new WalletMethod() );
		self::register( new CodMethod() );
	}

	public static function register( Method $method ): void {
		self::$methods[ $method->id() ] = $method;
	}

	/**
	 * @return array<string,Method> Every registered method, enabled or not.
	 */
	public static function all(): array {
		self::boot();

		return self::$methods;
	}

	/**
	 * @return array<string,Method>
	 */
	public static function enabled(): array {
		return array_filter( self::all(), static fn( Method $m ) => self::isEnabled( $m->id() ) );
	}

	public static function settingKey( string $id ): string {
		return 'payment_' . $id . '_enabled';
	}

	/**
	 * A method with no stored setting counts as enabled, so registering one puts
	 * it straight to work rather than leaving it silently off.
	 */
	public static function isEnabled( string $id ): bool {
		return 'no' !== Settings::get( self::settingKey( $id ), 'yes' );
	}

	public static function get( string $id ): ?Method {
		return self::all()[ $id ] ?? null;
	}

	public static function defaultId(): string {
		$enabled = self::enabled();

		return $enabled ? (string) array_key_first( $enabled ) : '';
	}

	public static function label( string $id ): string {
		$method = self::get( $id );

		return $method ? $method->label() : ucfirst( $id );
	}

	/**
	 * The method to charge an order to.
	 *
	 * @throws \RuntimeException When it does not exist or is switched off.
	 */
	public static function resolve( string $id ): Method {
		$id = '' !== $id ? $id : self::defaultId();

		if ( '' === $id ) {
			throw new \RuntimeException( 'No payment method is switched on. An organiser needs to enable one.' );
		}

		$method = self::get( $id );

		if ( ! $method ) {
			throw new \RuntimeException( 'That payment method does not exist.' );
		}

		if ( ! self::isEnabled( $id ) ) {
			throw new \RuntimeException( $method->label() . ' is not available.' );
		}

		return $method;
	}
}
