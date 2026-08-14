<?php

namespace PauseCafe;

/**
 * The organiser navigation: what is in it, and which way round it sits.
 *
 * Top or side is a property of the person, not the site. Two organisers can
 * disagree — one on a laptop who wants the sidebar, one on a phone in the
 * kitchen who does not — and neither should be able to change what the other
 * sees by saving a setting.
 *
 * The list of screens lives here rather than in a template because both
 * layouts, and the jump menu on small screens, all render from it.
 */
class AdminNav {

	public const TOP  = 'top';
	public const SIDE = 'side';

	/**
	 * @return array<string,string> Path => label, in the order they appear.
	 */
	public static function items(): array {
		return array(
			'/admin'           => 'Overview',
			'/admin/menu'      => 'Menu',
			'/admin/schedules' => 'Schedules',
			'/admin/fields'    => 'Fields',
			'/admin/orders'    => 'Orders',
			'/kitchen'         => 'Kitchen list',
			'/admin/users'     => 'People',
			'/admin/design'    => 'Design',
			'/admin/signin'    => 'Signing in',
			'/admin/settings'  => 'Settings',
		);
	}

	/** Which way this organiser wants it. Members never see it either way. */
	public static function style( ?array $user = null ): string {
		$user = $user ?? Auth::user();

		return self::SIDE === ( $user['admin_nav'] ?? self::TOP ) ? self::SIDE : self::TOP;
	}

	public static function setStyle( int $userId, string $style ): void {
		Users::update( $userId, array( 'admin_nav' => self::SIDE === $style ? self::SIDE : self::TOP ) );
	}

	/**
	 * Whether the current request is a screen the organiser navigation belongs
	 * on. Driven by the path so the layout can decide without every template
	 * having to announce itself.
	 */
	public static function appliesTo( string $path ): bool {
		$path = '/' . trim( (string) parse_url( $path, PHP_URL_PATH ), '/' );

		return str_starts_with( $path, '/admin' ) || str_starts_with( $path, '/kitchen' );
	}

	/** The item that should read as current, given a path. */
	public static function currentFor( string $path ): string {
		$path  = '/' . trim( (string) parse_url( $path, PHP_URL_PATH ), '/' );
		$found = '';

		foreach ( array_keys( self::items() ) as $href ) {
			// Longest match wins, so /admin/menu/builder lights up Menu rather
			// than Overview, and /admin only matches itself.
			$matches = '/admin' === $href ? '/admin' === $path : str_starts_with( $path, $href );

			if ( $matches && strlen( $href ) > strlen( $found ) ) {
				$found = $href;
			}
		}

		return $found;
	}
}
