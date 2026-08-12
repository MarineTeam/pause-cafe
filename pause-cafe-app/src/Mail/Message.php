<?php

namespace PauseCafe\Mail;

/**
 * One email, and the MIME it turns into.
 *
 * Every field that reaches a header is stripped of newlines first. A newline in
 * a subject or an address is how a header injection becomes a Bcc to somebody
 * else's mailing list.
 */
class Message {

	public string $toEmail = '';

	public string $toName = '';

	public string $subject = '';

	public string $text = '';

	public string $html = '';

	public string $replyTo = '';

	public static function make( string $toEmail, string $toName, string $subject, string $text, string $html = '' ): self {
		$message = new self();

		$message->toEmail = self::header( $toEmail );
		$message->toName  = self::header( $toName );
		$message->subject = self::header( $subject );
		$message->text    = $text;
		$message->html    = $html;

		return $message;
	}

	/**
	 * Strips anything that could start a new header line.
	 */
	public static function header( string $value ): string {
		return trim( str_replace( array( "\r", "\n", "\0" ), '', $value ) );
	}

	public static function isValidAddress( string $email ): bool {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	public function formattedTo(): string {
		return $this->formatAddress( $this->toEmail, $this->toName );
	}

	public function formatAddress( string $email, string $name ): string {
		$email = self::header( $email );
		$name  = self::header( $name );

		if ( '' === $name ) {
			return $email;
		}

		// Quote the display name so a comma or colon in it cannot split the header.
		return '"' . str_replace( '"', '', $name ) . '" <' . $email . '>';
	}

	/**
	 * Headers other than To and Subject, which some transports supply
	 * themselves.
	 *
	 * @return array<string,string>
	 */
	public function headers( string $fromName, string $fromEmail, string $boundary ): array {
		$headers = array(
			'From'         => $this->formatAddress( $fromEmail, $fromName ),
			'MIME-Version' => '1.0',
			'Date'         => gmdate( 'r' ),
		);

		if ( '' !== $this->replyTo ) {
			$headers['Reply-To'] = self::header( $this->replyTo );
		}

		$headers['Content-Type'] = '' !== $this->html
			? 'multipart/alternative; boundary="' . $boundary . '"'
			: 'text/plain; charset=UTF-8';

		return $headers;
	}

	public function boundary(): string {
		return 'pc-' . bin2hex( random_bytes( 12 ) );
	}

	/**
	 * The body, as plain text or as a multipart/alternative pair.
	 */
	public function body( string $boundary ): string {
		if ( '' === $this->html ) {
			return $this->normalise( $this->text );
		}

		$parts = array(
			'--' . $boundary,
			'Content-Type: text/plain; charset=UTF-8',
			'',
			$this->normalise( $this->text ),
			'--' . $boundary,
			'Content-Type: text/html; charset=UTF-8',
			'',
			$this->normalise( $this->html ),
			'--' . $boundary . '--',
			'',
		);

		return implode( "\r\n", $parts );
	}

	/**
	 * The whole thing, headers and body, ready for SMTP's DATA command.
	 */
	public function raw( string $fromName, string $fromEmail ): string {
		$boundary = $this->boundary();
		$headers  = $this->headers( $fromName, $fromEmail, $boundary );

		$headers = array_merge(
			array(
				'To'      => $this->formattedTo(),
				'Subject' => $this->encodeSubject( $this->subject ),
			),
			$headers
		);

		$lines = array();

		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		return implode( "\r\n", $lines ) . "\r\n\r\n" . $this->body( $boundary );
	}

	/**
	 * Non-ASCII subjects have to be encoded or they arrive as mojibake -- which
	 * matters here, where dish names are often Chinese.
	 */
	public function encodeSubject( string $subject ): string {
		if ( preg_match( '/^[\x20-\x7E]*$/', $subject ) ) {
			return $subject;
		}

		return '=?UTF-8?B?' . base64_encode( $subject ) . '?=';
	}

	private function normalise( string $body ): string {
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );

		return str_replace( "\n", "\r\n", $body );
	}
}
