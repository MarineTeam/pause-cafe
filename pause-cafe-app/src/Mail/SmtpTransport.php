<?php

namespace PauseCafe\Mail;

use PauseCafe\Settings;

/**
 * A minimal SMTP client, written against sockets rather than a library so the
 * app stays free of Composer.
 *
 * Handles the part of the protocol this app needs: greeting, EHLO, optional
 * STARTTLS, AUTH LOGIN, the envelope, DATA. It is not a general-purpose mailer
 * -- no pipelining, no attachments, one recipient per message.
 */
class SmtpTransport implements Transport {

	private const TIMEOUT = 15;

	public function id(): string {
		return 'smtp';
	}

	public function label(): string {
		return 'SMTP';
	}

	public function description(): string {
		return 'Sends through a mail server you already have — the church mailbox, Gmail, Fastmail.';
	}

	public function isConfigured(): bool {
		return '' !== Settings::get( 'smtp_host' );
	}

	public function configFields(): array {
		return array(
			'smtp_host'       => array(
				'label' => 'Server',
				'type'  => 'text',
				'help'  => 'e.g. smtp.gmail.com',
			),
			'smtp_port'       => array(
				'label' => 'Port',
				'type'  => 'number',
				'help'  => '587 for STARTTLS, 465 for SSL, 25 for none.',
			),
			'smtp_encryption' => array(
				'label'   => 'Encryption',
				'type'    => 'select',
				'help'    => 'Use STARTTLS unless the server insists otherwise.',
				'options' => array(
					'tls'  => 'STARTTLS',
					'ssl'  => 'SSL/TLS',
					'none' => 'None',
				),
			),
			'smtp_username'   => array(
				'label' => 'Username',
				'type'  => 'text',
				'help'  => 'Leave blank if the server needs no login.',
			),
			'smtp_password'   => array(
				'label' => 'Password',
				'type'  => 'password',
				'help'  => 'For Gmail this has to be an app password, not the account password.',
			),
		);
	}

	/**
	 * Splits an SMTP reply into a code and its text.
	 *
	 * Replies can span lines, with every line but the last marked by a hyphen
	 * after the code: "250-SIZE" then "250 HELP". Only the last line's code
	 * counts.
	 *
	 * @return array{code:int,text:string}
	 */
	public static function parseReply( string $reply ): array {
		$lines = preg_split( '/\r?\n/', trim( $reply ) ) ?: array();
		$last  = '';

		foreach ( $lines as $line ) {
			if ( '' !== trim( $line ) ) {
				$last = $line;
			}
		}

		if ( ! preg_match( '/^(\d{3})[ -]?(.*)$/', $last, $matches ) ) {
			return array(
				'code' => 0,
				'text' => trim( $reply ),
			);
		}

		return array(
			'code' => (int) $matches[1],
			'text' => trim( $matches[2] ),
		);
	}

	/**
	 * A line beginning with a dot would end the DATA block, so it is doubled.
	 */
	public static function stuffDots( string $data ): string {
		$data = str_replace( "\r\n.", "\r\n..", $data );

		return 0 === strncmp( $data, '.', 1 ) ? '.' . $data : $data;
	}

	public function send( Message $message, string $fromName, string $fromEmail ): Result {
		$host       = Settings::get( 'smtp_host' );
		$port       = Settings::int( 'smtp_port', 587 );
		$encryption = Settings::get( 'smtp_encryption', 'tls' );
		$username   = Settings::get( 'smtp_username' );
		$password   = Settings::get( 'smtp_password' );

		if ( '' === $host ) {
			return Result::failed( $this->id(), 'No SMTP server is set.' );
		}

		$target = ( 'ssl' === $encryption ? 'ssl://' : 'tcp://' ) . $host . ':' . $port;

		$socket = @stream_socket_client(
			$target,
			$errorNumber,
			$errorText,
			self::TIMEOUT,
			STREAM_CLIENT_CONNECT
		);

		if ( ! $socket ) {
			return Result::failed(
				$this->id(),
				'Could not connect to ' . $host . ':' . $port . ' — ' . ( $errorText ?: 'no response' ) . '.'
			);
		}

		stream_set_timeout( $socket, self::TIMEOUT );

		try {
			$this->expect( $socket, null, 220, 'greeting' );
			$this->expect( $socket, 'EHLO ' . $this->helo(), 250, 'EHLO' );

			if ( 'tls' === $encryption ) {
				$this->expect( $socket, 'STARTTLS', 220, 'STARTTLS' );

				$crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;

				if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT' ) ) {
					$crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
				}

				if ( ! @stream_socket_enable_crypto( $socket, true, $crypto ) ) {
					throw new \RuntimeException( 'The server would not start TLS.' );
				}

				// The server forgets everything it told us before TLS, so ask again.
				$this->expect( $socket, 'EHLO ' . $this->helo(), 250, 'EHLO after STARTTLS' );
			}

			if ( '' !== $username ) {
				$this->expect( $socket, 'AUTH LOGIN', 334, 'AUTH' );
				$this->expect( $socket, base64_encode( $username ), 334, 'username' );
				$this->expect( $socket, base64_encode( $password ), 235, 'password' );
			}

			$this->expect( $socket, 'MAIL FROM:<' . $fromEmail . '>', 250, 'MAIL FROM' );
			$this->expect( $socket, 'RCPT TO:<' . $message->toEmail . '>', array( 250, 251 ), 'RCPT TO' );
			$this->expect( $socket, 'DATA', 354, 'DATA' );

			$body = self::stuffDots( $message->raw( $fromName, $fromEmail ) );

			$this->write( $socket, $body . "\r\n." );
			$this->expect( $socket, null, 250, 'message body' );

			$this->write( $socket, 'QUIT' );
			fclose( $socket );

			return Result::sent( $this->id() );
		} catch ( \RuntimeException $e ) {
			if ( is_resource( $socket ) ) {
				fclose( $socket );
			}

			return Result::failed( $this->id(), $e->getMessage() );
		}
	}

	/**
	 * The name we announce ourselves by. Servers dislike a bare "localhost", so
	 * the site's own hostname is used when there is one.
	 */
	private function helo(): string {
		$host = (string) ( $_SERVER['SERVER_NAME'] ?? '' );

		if ( '' === $host || ! preg_match( '/^[A-Za-z0-9.\-]+$/', $host ) ) {
			$host = 'localhost';
		}

		return $host;
	}

	/**
	 * @param int|int[] $expected
	 * @throws \RuntimeException When the reply is not one of the expected codes.
	 */
	private function expect( $socket, ?string $command, $expected, string $step ): void {
		if ( null !== $command ) {
			$this->write( $socket, $command );
		}

		$reply    = $this->read( $socket );
		$parsed   = self::parseReply( $reply );
		$expected = (array) $expected;

		if ( ! in_array( $parsed['code'], $expected, true ) ) {
			throw new \RuntimeException(
				'The server refused at ' . $step . ': ' .
				( 0 === $parsed['code'] ? 'no reply' : $parsed['code'] . ' ' . $parsed['text'] )
			);
		}
	}

	private function write( $socket, string $line ): void {
		if ( false === @fwrite( $socket, $line . "\r\n" ) ) {
			throw new \RuntimeException( 'The connection dropped while sending.' );
		}
	}

	private function read( $socket ): string {
		$reply = '';

		while ( ! feof( $socket ) ) {
			$line = @fgets( $socket, 1024 );

			if ( false === $line ) {
				break;
			}

			$reply .= $line;

			// A space rather than a hyphen after the code marks the final line.
			if ( preg_match( '/^\d{3} /', $line ) ) {
				break;
			}

			$info = stream_get_meta_data( $socket );

			if ( ! empty( $info['timed_out'] ) ) {
				throw new \RuntimeException( 'The server stopped responding.' );
			}
		}

		return $reply;
	}
}
