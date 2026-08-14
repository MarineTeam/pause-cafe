<?php

namespace PauseCafe\SignIn;

/**
 * What happened when a sign-in method was asked to do something.
 *
 * Methods differ in how many steps they take. A password is decided on the
 * spot; a magic link ends the request by saying "check your email" and picks up
 * again minutes later; an identity provider sends the browser away and comes
 * back with a code. One return type covers all three so the routes do not have
 * to know which kind they are holding.
 */
final class Outcome {

	public const OK       = 'ok';
	public const REDIRECT = 'redirect';
	public const NOTICE   = 'notice';
	public const FAILURE  = 'failure';

	private function __construct(
		private string $kind,
		private ?array $user = null,
		private string $url = '',
		private string $message = ''
	) {}

	/** Sign-in succeeded and this is who it is. */
	public static function authenticated( array $user ): self {
		return new self( self::OK, $user );
	}

	/** Send the browser somewhere — an identity provider, usually. */
	public static function redirect( string $url ): self {
		return new self( self::REDIRECT, null, $url );
	}

	/**
	 * Nothing went wrong, but nobody is signed in yet.
	 *
	 * Used where the honest answer would leak something: a magic link says the
	 * same thing whether or not the address has an account.
	 */
	public static function notice( string $message ): self {
		return new self( self::NOTICE, null, '', $message );
	}

	public static function failure( string $message ): self {
		return new self( self::FAILURE, null, '', $message );
	}

	public function kind(): string {
		return $this->kind;
	}

	public function isAuthenticated(): bool {
		return self::OK === $this->kind && null !== $this->user;
	}

	public function user(): ?array {
		return $this->user;
	}

	public function url(): string {
		return $this->url;
	}

	public function message(): string {
		return $this->message;
	}
}
