<?php

namespace PauseCafe;

use PauseCafe\Mail\LogTransport;
use PauseCafe\Mail\Message;
use PauseCafe\Mail\PhpMailTransport;
use PauseCafe\Mail\ResendTransport;
use PauseCafe\Mail\Result;
use PauseCafe\Mail\SmtpTransport;
use PauseCafe\Mail\Transport;

/**
 * The register of ways to send email, and the one entry point for sending it.
 *
 * Nothing that sends a message names a transport. It builds a Message and hands
 * it here; which one carries it is a setting.
 *
 * If the chosen transport fails, the message is retried through PHP's mail().
 * Deliverability there is poor, but a confirmation that arrives in a spam
 * folder beats one that was silently dropped because an API key expired.
 */
class Mailer {

	/** @var array<string,Transport> */
	private static array $transports = array();

	private static bool $booted = false;

	/** @var Result[] Everything sent this request, for the tests to look at. */
	private static array $sent = array();

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::register( new PhpMailTransport() );
		self::register( new SmtpTransport() );
		self::register( new ResendTransport() );
		self::register( new LogTransport() );
	}

	public static function register( Transport $transport ): void {
		self::$transports[ $transport->id() ] = $transport;
	}

	/**
	 * @return array<string,Transport>
	 */
	public static function all(): array {
		self::boot();

		return self::$transports;
	}

	public static function get( string $id ): ?Transport {
		return self::all()[ $id ] ?? null;
	}

	public static function label( string $id ): string {
		$transport = self::get( $id );

		return $transport ? $transport->label() : ucfirst( $id );
	}

	public static function isEnabled(): bool {
		return 'no' !== Settings::get( 'mail_enabled', 'yes' );
	}

	public static function selectedId(): string {
		$id = Settings::get( 'mail_transport', 'php' );

		return null !== self::get( $id ) ? $id : 'php';
	}

	public static function selected(): Transport {
		return self::get( self::selectedId() ) ?? new PhpMailTransport();
	}

	public static function fromName(): string {
		$name = Settings::get( 'mail_from_name' );

		return '' !== $name ? $name : 'Pause Cafe';
	}

	/**
	 * A From address must exist or most servers reject the message outright, so
	 * an unset one falls back to no-reply at whatever host we are running on.
	 */
	public static function fromEmail(): string {
		$email = Settings::get( 'mail_from_email' );

		if ( Message::isValidAddress( $email ) ) {
			return $email;
		}

		$host = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
		$host = preg_replace( '/[^A-Za-z0-9.\-]/', '', explode( ':', $host )[0] );

		return 'no-reply@' . ( '' !== $host ? $host : 'localhost' );
	}

	/**
	 * Sends, falling back to mail() if the chosen transport cannot.
	 */
	public static function send( Message $message ): Result {
		self::boot();

		if ( ! self::isEnabled() ) {
			return self::record( Result::failed( 'none', 'Email is switched off in settings.' ) );
		}

		if ( ! Message::isValidAddress( $message->toEmail ) ) {
			return self::record( Result::failed( 'none', 'No valid address to send to.' ) );
		}

		$transport = self::selected();
		$result    = $transport->send( $message, self::fromName(), self::fromEmail() );

		if ( $result->ok ) {
			return self::record( $result );
		}

		error_log( 'pause-cafe mail: ' . $transport->id() . ' failed — ' . $result->message );

		if ( 'php' === $transport->id() ) {
			return self::record( $result );
		}

		$fallback = self::get( 'php' );

		if ( ! $fallback ) {
			return self::record( $result );
		}

		$retry = $fallback->send( $message, self::fromName(), self::fromEmail() );

		if ( ! $retry->ok ) {
			error_log( 'pause-cafe mail: fallback also failed — ' . $retry->message );

			return self::record(
				Result::failed(
					$transport->id(),
					$result->message . ' The fallback failed too: ' . $retry->message
				)
			);
		}

		$retry->viaFallback = true;

		return self::record( $retry );
	}

	private static function record( Result $result ): Result {
		self::$sent[] = $result;

		return $result;
	}

	/**
	 * @return Result[]
	 */
	public static function sentThisRequest(): array {
		return self::$sent;
	}

	public static function forget(): void {
		self::$sent = array();
	}
}
