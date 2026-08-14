<?php

namespace PauseCafe;

/**
 * Swappable sets of templates.
 *
 * A theme is a directory under themes/ holding a theme.php that describes it,
 * an optional style.css, and optional templates mirroring views/. Whatever it
 * does not provide falls through to the core views — the child-theme
 * arrangement, and the reason a theme can be four files rather than forty.
 *
 * Two things this deliberately does not do:
 *
 *   - **It never serves the directory.** themes/ lives beside views/, outside
 *     the document root, because the files in it are PHP that runs with full
 *     access to the app. The stylesheet reaches the browser through a route
 *     that reads one known filename, not by being reachable on disk.
 *
 *   - **It never trusts the setting.** The slug is matched against the
 *     directories that actually exist rather than being pasted into a path, so
 *     a stored value of "../../etc" resolves to no theme instead of to
 *     somewhere alarming. A theme that is deleted while selected falls back to
 *     core rather than fataling every page at once.
 */
class Themes {

	private static string $root = '';

	/** @var array<string,array>|null */
	private static ?array $found = null;

	public static function configure( string $root ): void {
		self::$root  = rtrim( $root, '/\\' );
		self::$found = null;
	}

	/**
	 * Every installed theme, keyed by slug.
	 *
	 * @return array<string,array{name:string,description:string,author:string,path:string}>
	 */
	public static function all(): array {
		if ( null !== self::$found ) {
			return self::$found;
		}

		self::$found = array();

		if ( '' === self::$root || ! is_dir( self::$root ) ) {
			return self::$found;
		}

		foreach ( (array) scandir( self::$root ) as $entry ) {
			$entry = (string) $entry;

			// The slug becomes part of a path, so it is held to characters that
			// cannot climb out of the directory whatever else happens.
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $entry ) ) {
				continue;
			}

			$path     = self::$root . '/' . $entry;
			$manifest = $path . '/theme.php';

			if ( ! is_dir( $path ) || ! is_file( $manifest ) ) {
				continue;
			}

			$details = include $manifest;

			if ( ! is_array( $details ) ) {
				continue;
			}

			self::$found[ $entry ] = array(
				'name'        => (string) ( $details['name'] ?? $entry ),
				'description' => (string) ( $details['description'] ?? '' ),
				'author'      => (string) ( $details['author'] ?? '' ),
				'path'        => $path,
			);
		}

		ksort( self::$found );

		return self::$found;
	}

	/** The active slug, or '' for the built-in views. */
	public static function slug(): string {
		$slug = Settings::get( 'design_theme' );

		return isset( self::all()[ $slug ] ) ? $slug : '';
	}

	/**
	 * @return array|null Details of the active theme.
	 */
	public static function active(): ?array {
		$slug = self::slug();

		return '' !== $slug ? self::all()[ $slug ] : null;
	}

	/** Directory of the active theme, or '' — what View stacks in front. */
	public static function path(): string {
		$theme = self::active();

		return $theme ? $theme['path'] : '';
	}

	/** Where the active theme keeps its templates, or ''. */
	public static function viewPath(): string {
		$path = self::path();

		return '' !== $path && is_dir( $path . '/views' ) ? $path . '/views' : '';
	}

	/** The active theme's stylesheet on disk, or '' if it has none. */
	public static function stylesheet(): string {
		$path = self::path();

		return '' !== $path && is_file( $path . '/style.css' ) ? $path . '/style.css' : '';
	}

	/**
	 * The URL for that stylesheet, carrying its modification time so a changed
	 * theme is not served from a stale cache.
	 */
	public static function stylesheetUrl(): string {
		$file = self::stylesheet();

		return '' !== $file ? '/theme.css?v=' . (int) filemtime( $file ) : '';
	}

	public static function isValid( string $slug ): bool {
		return '' === $slug || isset( self::all()[ $slug ] );
	}
}
