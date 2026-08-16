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
 *   - Once made, the link is on the provider's subject, not the email. Subjects
 *     do not change; addresses do, and matching on an address every time would
 *     hand a wallet to whoever inherits the address.
 *
 *   - An unverified address never matches an existing account. Without this, a
 *     provider that lets anyone claim any address lets anyone claim any wallet.
 *
 *   - Making the link in the first place is the weak point, because there is no
 *     subject to go on yet and only the address is left. Where the account has
 *     money, a history, or the admin area behind it, the match is parked for an
 *     organiser instead of acted on. Everything above is worth nothing if the
 *     first link can be had by whoever holds the address this month.
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

	/**
	 * Whether an organiser has ever actually got in through a provider.
	 *
	 * Rows here are only written after a provider has verified somebody, so one
	 * belonging to an organiser is proof that the outside route worked at least
	 * once for somebody who would need it. Settings being filled in proves
	 * nothing: a client secret with a typo in it is configured.
	 *
	 * This is what the password rescue is allowed to be switched off against.
	 */
	public static function provenForAdmin(): bool {
		$count = Database::pdo()->query(
			"SELECT COUNT(*) FROM user_identities i
			 JOIN users u ON u.id = i.user_id
			 WHERE u.role = '" . Users::ROLE_ADMIN . "' AND i.last_seen_at != ''"
		)->fetchColumn();

		return (int) $count > 0;
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
	 * Whether attaching an external account to this one needs a human first.
	 *
	 * The question is what somebody would get by arriving with a verified
	 * address that happens to match: money, a history of orders, or the run of
	 * the admin area. An account with none of those is not worth taking, and
	 * making its owner wait for an organiser would cost far more than it saves
	 * -- the ordinary case is a member whose account an organiser typed in last
	 * month signing in with Google for the first time.
	 *
	 * Role counts as much as money. An organiser account with an empty wallet
	 * is the most valuable thing on the site.
	 */
	public static function needsApproval( int $userId ): bool {
		$user = Users::find( $userId );

		if ( ! $user ) {
			return true;
		}

		if ( Users::ROLE_ADMIN === (string) $user['role'] ) {
			return true;
		}

		$pdo = Database::pdo();

		$wallet = $pdo->prepare( 'SELECT COUNT(*) FROM wallet_entries WHERE user_id = ?' );
		$wallet->execute( array( $userId ) );

		if ( (int) $wallet->fetchColumn() > 0 ) {
			return true;
		}

		$orders = $pdo->prepare( 'SELECT COUNT(*) FROM orders WHERE user_id = ?' );
		$orders->execute( array( $userId ) );

		return (int) $orders->fetchColumn() > 0;
	}

	/**
	 * Parks a claim for an organiser to look at, and returns it.
	 *
	 * Repeated attempts update the one row rather than piling up, so somebody
	 * trying again every day is one item on the screen with a recent date on it
	 * -- and cannot bury the list under a hundred entries.
	 */
	public static function requestLink( int $userId, string $provider, string $subject, string $email, string $name = '' ): void {
		$pdo = Database::pdo();
		$now = gmdate( 'Y-m-d H:i:s' );

		$statement = $pdo->prepare(
			'UPDATE identity_link_requests
			 SET user_id = ?, email = ?, name = ?, last_try_at = ?
			 WHERE provider = ? AND subject = ?'
		);

		$statement->execute( array( $userId, $email, $name, $now, $provider, $subject ) );

		if ( $statement->rowCount() > 0 ) {
			return;
		}

		$pdo->prepare(
			'INSERT INTO identity_link_requests
				(user_id, provider, subject, email, name, created_at, last_try_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?)'
		)->execute( array( $userId, $provider, $subject, $email, $name, $now, $now ) );
	}

	/**
	 * Claims waiting on an organiser, with the account each one wants.
	 *
	 * @return array[]
	 */
	public static function pendingLinks(): array {
		return Database::pdo()->query(
			'SELECT r.*, u.name AS user_name, u.email AS user_email, u.role
			 FROM identity_link_requests r
			 JOIN users u ON u.id = r.user_id
			 ORDER BY r.created_at'
		)->fetchAll();
	}

	public static function pendingLink( int $id ): ?array {
		$statement = Database::pdo()->prepare( 'SELECT * FROM identity_link_requests WHERE id = ?' );
		$statement->execute( array( $id ) );

		return $statement->fetch() ?: null;
	}

	public static function declineLink( int $id ): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM identity_link_requests WHERE id = ?' );
		$statement->execute( array( $id ) );
	}

	/**
	 * An organiser says yes: the link is made and the claim is spent.
	 *
	 * Approving does not sign anybody in. The person goes back to the login
	 * page and comes through the provider again, which is the same path as
	 * every other sign-in and does not depend on the organiser and the member
	 * being at their screens at the same moment.
	 *
	 * @return bool False when the claim has gone, or the account with it.
	 */
	public static function approveLink( int $id ): bool {
		$request = self::pendingLink( $id );

		if ( ! $request || ! Users::find( (int) $request['user_id'] ) ) {
			self::declineLink( $id );

			return false;
		}

		// Something linked that subject while the claim sat here. Spend it
		// rather than writing a second row against the unique index.
		if ( self::find( (string) $request['provider'], (string) $request['subject'] ) ) {
			self::declineLink( $id );

			return true;
		}

		self::link(
			(int) $request['user_id'],
			(string) $request['provider'],
			(string) $request['subject'],
			(string) $request['email']
		);

		self::declineLink( $id );

		return true;
	}

	/**
	 * Connects a provider to somebody who has already proved who they are.
	 *
	 * The counterpart to resolve(), and the difference is the whole reason both
	 * exist. resolve() is asked "whose account is this?" and, first time out,
	 * has only an address to go on. This is not asked that at all: the account
	 * is the one holding the session, established by a credential this site
	 * issued. The address is recorded for the organiser screen and decides
	 * nothing — it does not even have to match the one on the account, which is
	 * the ordinary case for somebody connecting a personal login to an account
	 * opened under a work address.
	 *
	 * Nothing here can create an account or change a role.
	 */
	public static function attach( int $userId, string $provider, Profile $profile ): Outcome {
		$user = Users::find( $userId );

		if ( ! $user ) {
			return Outcome::failure( 'That account could not be found. Please sign in again.' );
		}

		if ( '' === trim( $profile->subject ) ) {
			return Outcome::failure( 'Your sign-in provider did not say who you are. Please try again.' );
		}

		$existing = self::find( $provider, $profile->subject );

		if ( $existing ) {
			if ( (int) $existing['user_id'] === $userId ) {
				self::touch( (int) $existing['id'], $profile->email );

				return Outcome::notice( 'That is already connected to your account.' );
			}

			/*
			 * Somebody else here already signs in with it. Moving it would take
			 * their way in away and hand it to whoever asked last, so it stays
			 * where it is and an organiser can sort out which of the two
			 * accounts is the real one.
			 */
			return Outcome::failure(
				'That account is already connected to somebody else here. Please speak to an organiser.'
			);
		}

		self::link( $userId, $provider, $profile->subject, $profile->email );

		/*
		 * They have just done for themselves what a claim was waiting on an
		 * organiser to do. Leaving it on the screen would only invite somebody
		 * to approve a link that already exists.
		 */
		self::forgetRequest( $provider, $profile->subject );

		return Outcome::authenticated( $user );
	}

	/**
	 * How many ways somebody would still have in without one of their links.
	 *
	 * Disconnecting the last one is the quiet way to lock yourself out, and it
	 * is easiest for exactly the people it hurts most: somebody an organiser
	 * created an account for, who has never had a password and signs in only
	 * through a provider. A link they can undo in one click is the whole of
	 * their access.
	 *
	 * A method that is switched off does not count, and neither does a password
	 * they do not have.
	 */
	public static function waysInWithout( int $userId, int $identityId = 0 ): int {
		$user = Users::find( $userId );

		if ( ! $user ) {
			return 0;
		}

		$ways = 0;

		if ( SignIn::isAvailable( 'password' ) && Users::hasPassword( $user ) ) {
			++$ways;
		}

		// An emailed link needs nothing but the address on the account, so it
		// is a way in for everybody the moment it is switched on.
		if ( SignIn::isAvailable( 'magic' ) ) {
			++$ways;
		}

		foreach ( self::forUser( $userId ) as $link ) {
			if ( (int) $link['id'] !== $identityId && SignIn::isAvailable( (string) $link['provider'] ) ) {
				++$ways;
			}
		}

		return $ways;
	}

	/** Drops any parked claim for one subject, however it was settled. */
	public static function forgetRequest( string $provider, string $subject ): void {
		$statement = Database::pdo()->prepare(
			'DELETE FROM identity_link_requests WHERE provider = ? AND subject = ?'
		);

		$statement->execute( array( $provider, $subject ) );
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

			// The account was removed, or closed, but the link outlived it.
			// Either way the link is spent -- a closed account that a provider
			// can still open is not closed.
			if ( ! $user || Users::isDisabled( $user ) ) {
				self::unlink( (int) $existing['id'] );

				return Outcome::failure( 'That account is no longer active. Please speak to an organiser.' );
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
			/*
			 * A verified address is good evidence and poor proof.
			 *
			 * It says the provider believes this person can read that mailbox
			 * today. It does not say they are the person who opened the account
			 * here -- addresses are reassigned inside an organisation, recycled
			 * by a provider, and issued by tenants somebody else administers.
			 * Where the account holds money, has a history, or is an
			 * organiser's, that gap is worth a human closing.
			 *
			 * So the claim is parked rather than acted on, and an organiser who
			 * knows the congregation decides. Accounts with nothing to take
			 * still link on the spot, because waiting would cost their owners
			 * far more than it protects.
			 */
			if ( self::needsApproval( (int) $user['id'] ) ) {
				self::requestLink( (int) $user['id'], $provider, $profile->subject, $profile->email, $profile->name );

				return Outcome::failure(
					'There is already an account here for ' . $profile->email . '. '
					. 'For safety it is not joined to a new sign-in automatically, so an organiser has been '
					. 'asked to confirm it is you. Try again once they have, or sign in the way you usually do.'
				);
			}

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
