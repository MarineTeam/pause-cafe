<?php

namespace PauseCafe\SignIn;

use PauseCafe\Users;

/**
 * Email address and password, held here.
 *
 * What the site has always done, now behind the same interface as everything
 * else. It needs no configuration and works with no network, which is why it is
 * the one SignIn::available() falls back to when nothing else can run.
 */
class PasswordMethod implements Method {

	public function id(): string {
		return 'password';
	}

	public function label(): string {
		return 'Password';
	}

	public function describe(): string {
		return 'An email address and a password, kept on this site. Needs no setup and works offline.';
	}

	public function enabledByDefault(): bool {
		return true;
	}

	public function isConfigured(): bool {
		return true;
	}

	public function requirement(): string {
		return '';
	}

	public function fields(): array {
		return array();
	}

	public function prompt(): string {
		return self::PROMPT_PASSWORD;
	}

	public function start( array $input ): Outcome {
		$user = Users::authenticate(
			(string) ( $input['email'] ?? '' ),
			(string) ( $input['password'] ?? '' )
		);

		if ( ! $user ) {
			/*
			 * One message for a wrong password, an unknown address and an
			 * account that has no password at all. Distinguishing them would
			 * say who has an account here.
			 */
			return Outcome::failure( 'That email and password did not match.' );
		}

		return Outcome::authenticated( $user );
	}

	public function finish( array $input ): Outcome {
		return Outcome::failure( 'Passwords are checked as they are entered.' );
	}
}
