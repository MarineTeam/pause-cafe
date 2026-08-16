<?php

namespace PauseCafe\SignIn;

use PauseCafe\LoginTokens;
use PauseCafe\Mailer;
use PauseCafe\Notifications;
use PauseCafe\Settings;
use PauseCafe\Users;

/**
 * A one-time link, emailed.
 *
 * Suits a congregation that orders lunch once a week and forgets its password
 * between times. It moves the proof to the inbox: whoever can read the email
 * can sign in, which is already true of any site with a password reset.
 *
 * Three things it deliberately will not say:
 *
 *   - whether the address has an account. Every request gets the same answer,
 *     so the form cannot be used to find out who is a member.
 *   - whether a link has already been spent, versus never existed.
 *   - anything at all, if email is not working. isConfigured() checks that
 *     first, so the method never appears as an option that silently fails.
 */
class MagicLinkMethod implements Method {

	private const DEFAULT_MINUTES = 15;

	public function id(): string {
		return 'magic';
	}

	public function label(): string {
		return 'Email a sign-in link';
	}

	public function describe(): string {
		return 'No password. They type their address, we email a link that signs them in once.';
	}

	public function enabledByDefault(): bool {
		return false;
	}

	public function isConfigured(): bool {
		// A link nobody can be sent is not a way of signing in.
		return Mailer::isEnabled();
	}

	public function requirement(): string {
		return 'Email is switched off, so no link could be sent. Turn it on under Settings.';
	}

	public function fields(): array {
		return array(
			'signin_magic_minutes' => array(
				'label'       => 'Link lasts for',
				'type'        => 'text',
				'help'        => 'Minutes before the link stops working. Short is safer; 15 is a good default.',
				'placeholder' => (string) self::DEFAULT_MINUTES,
			),
		);
	}

	public function prompt(): string {
		return self::PROMPT_EMAIL;
	}

	public function minutes(): int {
		$minutes = Settings::int( 'signin_magic_minutes', self::DEFAULT_MINUTES );

		// A link good for a week is not a one-time link in any useful sense.
		return max( 1, min( 1440, $minutes ) );
	}

	public function start( array $input ): Outcome {
		$email = Users::normaliseEmail( (string) ( $input['email'] ?? '' ) );

		/*
		 * The same sentence comes back whatever happens below — unknown
		 * address, throttled account, mail server down. Anything else turns
		 * this box into a membership list.
		 */
		$said = Outcome::notice(
			'If there is an account for that address, a sign-in link is on its way. It lasts '
			. $this->minutes() . ' minutes.'
		);

		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return $said;
		}

		$user = Users::findByEmail( $email );

		/*
		 * A closed account is treated exactly like an address with no account:
		 * the same reply, and no email sent. Saying anything else would turn
		 * this box into a way of asking which accounts have been closed.
		 */
		if ( ! $user || Users::isDisabled( $user ) ) {
			return $said;
		}

		if ( LoginTokens::isThrottled( (int) $user['id'] ) ) {
			return $said;
		}

		$token = LoginTokens::issue( (int) $user['id'], $this->minutes() );
		$base  = Notifications::baseUrl();

		if ( '' === $base ) {
			return $said;
		}

		Notifications::signInLink(
			$user,
			$base . '/auth/magic/callback?token=' . rawurlencode( $token ),
			$this->minutes()
		);

		return $said;
	}

	public function finish( array $input ): Outcome {
		$user = LoginTokens::consume( (string) ( $input['token'] ?? '' ) );

		if ( ! $user ) {
			return Outcome::failure(
				'That sign-in link has been used already, or it has expired. Ask for a new one.'
			);
		}

		return Outcome::authenticated( $user );
	}
}
