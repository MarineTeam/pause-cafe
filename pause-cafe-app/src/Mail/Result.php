<?php

namespace PauseCafe\Mail;

/**
 * What happened when a transport tried to send.
 */
class Result {

	public bool $ok;

	public string $transport;

	public string $message;

	/** True when the message went out through the last-resort transport. */
	public bool $viaFallback = false;

	private function __construct( bool $ok, string $transport, string $message ) {
		$this->ok        = $ok;
		$this->transport = $transport;
		$this->message   = $message;
	}

	public static function sent( string $transport, string $message = 'Sent.' ): self {
		return new self( true, $transport, $message );
	}

	public static function failed( string $transport, string $message ): self {
		return new self( false, $transport, $message );
	}
}
