<?php

namespace PauseCafe\Mail;

use PauseCafe\Settings;

/**
 * Writes the email to a file instead of sending it.
 *
 * For staging, and for working out why a message looks wrong without posting it
 * to a real person. Also what the tests use, so a test run never tries to reach
 * a mail server.
 */
class LogTransport implements Transport {

	private static string $path = '';

	public static function configure( string $path ): void {
		self::$path = $path;
	}

	public static function path(): string {
		return self::$path;
	}

	public function id(): string {
		return 'log';
	}

	public function label(): string {
		return 'Write to a file';
	}

	public function description(): string {
		return 'Nothing is sent. Messages are appended to data/mail.log for checking.';
	}

	public function isConfigured(): bool {
		return '' !== self::$path;
	}

	public function configFields(): array {
		return array();
	}

	public function send( Message $message, string $fromName, string $fromEmail ): Result {
		if ( '' === self::$path ) {
			return Result::failed( $this->id(), 'No log path is configured.' );
		}

		$directory = dirname( self::$path );

		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0775, true );
		}

		$entry = str_repeat( '=', 70 ) . "\n" .
			gmdate( 'c' ) . "\n" .
			$message->raw( $fromName, $fromEmail ) . "\n";

		$written = file_put_contents( self::$path, $entry, FILE_APPEND | LOCK_EX );

		return false !== $written
			? Result::sent( $this->id(), 'Written to ' . basename( self::$path ) . '.' )
			: Result::failed( $this->id(), 'Could not write to ' . self::$path . '.' );
	}
}
