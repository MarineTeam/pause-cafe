<?php

namespace PauseCafe\Mail;

/**
 * PHP's built-in mail().
 *
 * Needs no configuration, which is why it is the last resort: when a configured
 * transport fails, this is what the message falls back to. Deliverability is
 * whatever the host's sendmail gives you, which on shared hosting is often
 * "straight to spam" -- fine as a safety net, poor as a first choice.
 */
class PhpMailTransport implements Transport {

	public function id(): string {
		return 'php';
	}

	public function label(): string {
		return 'PHP mail()';
	}

	public function description(): string {
		return 'The server\'s own mail command. No setup, but often lands in spam.';
	}

	public function isConfigured(): bool {
		return function_exists( 'mail' );
	}

	public function configFields(): array {
		return array();
	}

	public function send( Message $message, string $fromName, string $fromEmail ): Result {
		if ( ! function_exists( 'mail' ) ) {
			return Result::failed( $this->id(), 'mail() is disabled on this server.' );
		}

		$boundary = $message->boundary();
		$headers  = $message->headers( $fromName, $fromEmail, $boundary );
		$lines    = array();

		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		// mail() takes To and Subject as its own arguments, so they are left out
		// of the header block to avoid sending them twice.
		$sent = @mail(
			$message->formattedTo(),
			$message->encodeSubject( $message->subject ),
			$message->body( $boundary ),
			implode( "\r\n", $lines )
		);

		return $sent
			? Result::sent( $this->id() )
			: Result::failed( $this->id(), 'mail() returned false. Check the server\'s mail log.' );
	}
}
