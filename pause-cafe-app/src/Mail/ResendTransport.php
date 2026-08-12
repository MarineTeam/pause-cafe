<?php

namespace PauseCafe\Mail;

use PauseCafe\Settings;

/**
 * Resend's HTTP API.
 *
 * An API call rather than SMTP, so nothing depends on outbound port 25 or 587
 * being open — which on shared hosting it often is not. Needs an API key and a
 * verified sending domain at Resend's end.
 */
class ResendTransport implements Transport {

	private const ENDPOINT = 'https://api.resend.com/emails';

	public function id(): string {
		return 'resend';
	}

	public function label(): string {
		return 'Resend';
	}

	public function description(): string {
		return 'Sends over HTTPS with an API key. The From domain has to be verified at Resend.';
	}

	public function isConfigured(): bool {
		return '' !== Settings::get( 'resend_api_key' ) && function_exists( 'curl_init' );
	}

	public function configFields(): array {
		return array(
			'resend_api_key' => array(
				'label' => 'API key',
				'type'  => 'password',
				'help'  => 'From the Resend dashboard. Starts with re_.',
			),
		);
	}

	public function send( Message $message, string $fromName, string $fromEmail ): Result {
		$key = Settings::get( 'resend_api_key' );

		if ( '' === $key ) {
			return Result::failed( $this->id(), 'No Resend API key is set.' );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return Result::failed( $this->id(), 'This server has no cURL, so the API cannot be reached.' );
		}

		$payload = array(
			'from'    => $message->formatAddress( $fromEmail, $fromName ),
			'to'      => array( $message->toEmail ),
			'subject' => $message->subject,
			'text'    => $message->text,
		);

		if ( '' !== $message->html ) {
			$payload['html'] = $message->html;
		}

		if ( '' !== $message->replyTo ) {
			$payload['reply_to'] = $message->replyTo;
		}

		$curl = curl_init( self::ENDPOINT );

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST           => true,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_POSTFIELDS     => self::encode( $payload ),
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Bearer ' . $key,
					'Content-Type: application/json',
				),
			)
		);

		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $curl );

		curl_close( $curl );

		if ( false === $body || '' !== $error ) {
			return Result::failed( $this->id(), 'Could not reach Resend: ' . $error );
		}

		if ( $status < 200 || $status >= 300 ) {
			return Result::failed( $this->id(), self::explain( $status, (string) $body ) );
		}

		return Result::sent( $this->id() );
	}

	/**
	 * Turns Resend's error body into something an organiser can act on.
	 */
	public static function explain( int $status, string $body ): string {
		$decoded = json_decode( $body, true );
		$detail  = is_array( $decoded ) ? (string) ( $decoded['message'] ?? '' ) : '';

		if ( 401 === $status || 403 === $status ) {
			return 'Resend rejected the API key.' . ( '' !== $detail ? ' ' . $detail : '' );
		}

		if ( 422 === $status ) {
			return 'Resend rejected the message, usually because the From domain is not verified.' .
				( '' !== $detail ? ' ' . $detail : '' );
		}

		return 'Resend replied with HTTP ' . $status . '.' . ( '' !== $detail ? ' ' . $detail : '' );
	}

	/**
	 * Dish names are often Chinese, so the payload keeps them as UTF-8 rather
	 * than escaping them, and a malformed string yields an empty object instead
	 * of json_encode's false.
	 */
	private static function encode( array $payload ): string {
		$json = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '{}' : $json;
	}
}
