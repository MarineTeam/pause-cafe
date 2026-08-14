<?php

namespace PauseCafe;

/**
 * Plain PHP templates, rendered into a layout.
 */
class View {

	/** @var string[] Searched in order; the first file found wins. */
	private static array $roots = array();

	/**
	 * @param string $core  The built-in views directory.
	 * @param string $theme A theme's views directory, searched first.
	 */
	public static function configure( string $core, string $theme = '' ): void {
		self::$roots = array();

		foreach ( array( $theme, $core ) as $root ) {
			$root = rtrim( $root, '/\\' );

			if ( '' !== $root ) {
				self::$roots[] = $root;
			}
		}
	}

	/**
	 * @param array $data Extracted into the template's scope.
	 */
	public static function render( string $template, array $data = array(), string $layout = 'layout' ): string {
		$content = self::capture( $template, $data );

		if ( '' === $layout ) {
			return $content;
		}

		return self::capture(
			$layout,
			array_merge(
				$data,
				array(
					'content' => $content,
					'title'   => $data['title'] ?? '',
				)
			)
		);
	}

	/**
	 * The file backing a template, or '' when nothing provides it.
	 *
	 * A theme overrides one template at a time: it supplies menu.php and
	 * inherits every other screen. The trade-off is that a theme's copy will
	 * not pick up later changes to the core one.
	 */
	public static function locate( string $template ): string {
		$relative = str_replace( '..', '', $template ) . '.php';

		foreach ( self::$roots as $root ) {
			$file = $root . '/' . $relative;

			if ( is_file( $file ) ) {
				return $file;
			}
		}

		return '';
	}

	private static function capture( string $template, array $data ): string {
		$file = self::locate( $template );

		if ( '' === $file ) {
			throw new \RuntimeException( 'Missing template: ' . $template );
		}

		extract( $data, EXTR_SKIP );

		$depth = ob_get_level();

		ob_start();

		try {
			include $file;

			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			/*
			 * Without this, a template that throws leaves its buffer open. The
			 * half-rendered page is then flushed at shutdown alongside the error
			 * page, which reads as a mangled success rather than a failure -- and
			 * that is a great deal harder to diagnose than a clean 500.
			 */
			while ( ob_get_level() > $depth ) {
				ob_end_clean();
			}

			throw $e;
		}
	}

	public static function flash( string $type, string $message ): void {
		$_SESSION['flash'][] = array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * @return array[] Messages, cleared as they are read.
	 */
	public static function takeFlash(): array {
		$messages = $_SESSION['flash'] ?? array();

		unset( $_SESSION['flash'] );

		return $messages;
	}

	public static function redirect( string $path ): never {
		header( 'Location: ' . $path, true, 302 );
		exit;
	}
}

/**
 * Escape for HTML. Short because it appears in every template, on every value.
 */
function e( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
