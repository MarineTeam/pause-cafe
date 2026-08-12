<?php

namespace PauseCafe;

/**
 * Plain PHP templates, rendered into a layout.
 */
class View {

	private static string $root = '';

	public static function configure( string $root ): void {
		self::$root = rtrim( $root, '/\\' );
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

	private static function capture( string $template, array $data ): string {
		$file = self::$root . '/' . str_replace( '..', '', $template ) . '.php';

		if ( ! is_file( $file ) ) {
			throw new \RuntimeException( 'Missing template: ' . $template );
		}

		extract( $data, EXTR_SKIP );

		ob_start();

		include $file;

		return (string) ob_get_clean();
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
