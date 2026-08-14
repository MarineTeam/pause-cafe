<?php

namespace PauseCafe;

use PauseCafe\SignIn\Outcome;
use PauseCafe\SignIn\Profile;

/**
 * The join between an external account and a local one.
 *
 * This is the only place an outside service is allowed to turn into a signed-in
 * person, and the rules it applies are the ones that keep the wallet safe:
 *
 *   - The link is on the provider's subject, not the email. Subjects do not
 *     change; addresses do, and matching on an address would hand a wallet to
 *     whoever inherits the address.
 *
 *   - An unverified address never matches an existing account. Without this, a
 *     provider that lets anyone claim any address lets anyone claim any wallet.
 *
 *   - Nothing arriving from outside sets role or approval. A first-time signer
 *     lands unapproved, exactly like filling in the sign-up form, and an
 *     organiser still has to let them in before they can order.
 */
class Identities {

	public static function find( string $provider, string $subject ): ?array {
		$statement = Database::pdo()->prepare(
			'SELECT * FROM user_identities WHERE provider = ? AND subject = ?'
		);

		$statement->execute( array( $provider, $subject ) );

		return $statement->fetch() ?: null;
	}

	/**
	 * @return array[] Every external account linked to this person.
	 */
	public static function forUser( int $userId ): array {
		$statement = Database::pdo()->prepare(
			'SELECT * FROM user_identities WHERE user_id = ? ORDER BY provider'
		);

		$statement->execute( array( $userId ) );

		return $statement->fetchAll();
	}

	/**
	 * Every link, with the person it belongs to, for the organiser screen.
	 *
	 * The subject is not selected. It is an opaque string that identifies
	 * somebody at the provider, and putting it on a page is all risk and no
	 * use — the address and the name are what an organiser recognises.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		return Database::pdo()->query(
			'SELECT i.id, i.provider, i.last_seen_at, i.email AS identity_email,
					u.name, u.email
			 FROM user_identities i
			 JOIN users u ON u.id = i.user_id
			 ORDER BY u.name COLLATE NOCASE ASC, i.provider ASC'
		)->fetchAll();
	}

	public static function link( int $userId, string $provider, string $subject, string $email ): void {
		$statement = Database::pdo()->prepare(
			'INSERT INTO user_identities (user_id, provider, subject, email, created_at, last_seen_at)
			 VALUES (?, ?, ?, ?, ?, ?)'
		);

		$now = gmdate( 'Y-m-d H:i:s' );

		$statement->execute( array( $userId, $provider, $subject, $email, $now, $now ) );
	}

	public static function touch( int $id, string $email ): void {
		/*
		 * The address is recorded as the provider last gave it, but it is never
		 * copied onto the user row. Someone who changes their address at the
		 * provider keeps the account they have here; changing it here is a
		 * deliberate act by an organiser.
		 */
		$statement = Database::pdo()->prepare(
			'UPDATE user_identities SET last_seen_at = ?, email = ? WHERE id = ?'
		);

		$statement->execute( array( gmdate( 'Y-m-d H:i:s' ), $email, $id ) );
	}

	public static function unlink( int $id ): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM user_identities WHERE id = ?' );
		$statement->execute( array( $id ) );
	}

	/** Whether first-time external signers get an account made for them. */
	public static function mayCreateAccounts(): bool {
		return 'no' !== Settings::get( 'signin_external_create', 'yes' );
	}

	/**
	 * Turns a verified external profile into somebody who is signed in.
	 *
	 * The profile must already have been checked — a signature verified, a
	 * token exchanged. This decides only what it means for this site.
	 */
	public static function resolve( string $provider, Profile $profile ): Outcome {
		if ( ! $profile->isUsable() ) {
			return Outcome::failure( 'Your sign-in provider did not say who you are. Please try again.' );
		}

		$existing = self::find( $provider, $profile->subject );

		if ( $existing ) {
			$user = Users::find( (int) $existing['user_id'] );

			// The account was deleted but the link outlived it.
			if ( ! $user ) {
				self::unlink( (int) $existing['id'] );

				return Outcome::failure( 'That account has been removed. Please speak to an organiser.' );
			}

			self::touch( (int) $existing['id'], $profile->email );

			return Outcome::authenticated( $user );
		}

		/*
		 * No link yet, so the address is about to decide which account this
		 * becomes. It only gets to do that if the provider vouches for it.
		 */
		if ( ! $profile->emailVerified ) {
			return Outcome::failure(
				'Your sign-in provider has not confirmed that email address, so it cannot be used here. '
				. 'Confirm it with them and try again.'
			);
		}

		$user = Users::findByEmail( $profile->email );

		if ( $user ) {
			self::link( (int) $user['id'], $provider, $profile->subject, $profile->email );

			return Outcome::authenticated( $user );
		}

		if ( ! self::mayCreateAccounts() ) {
			return Outcome::failure(
				'There is no account here for ' . $profile->email . '. An organiser can make you one.'
			);
		}

		$userId = Users::createExternal( $profile->email, $profile->name );

		self::link( $userId, $provider, $profile->subject, $profile->email );

		// Nobody is waiting on this, and a mail failure must not turn a
		// successful sign-in into an error page.
		Notifications::newRegistration( $userId );

		$user = Users::find( $userId );

		return $user
			? Outcome::authenticated( $user )
			: Outcome::failure( 'Your account could not be created. Please speak to an organiser.' );
	}
}
