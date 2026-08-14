<?php

namespace PauseCafe;

use PauseCafe\Mail\Message;
use PauseCafe\Mail\Result;

/**
 * The emails this app actually sends.
 *
 * Plain text on purpose. There is nothing here a layout would help with, and
 * text arrives intact through every client and filter.
 *
 * Every method fails quietly. An email that cannot be sent must never take down
 * the order that triggered it.
 */
class Notifications {

	/**
	 * What to call this site in an email.
	 *
	 * The Design screen owns the name now. The mail-from name is kept as a
	 * fallback because that is where it used to live, and an install that set
	 * it there should not start sending mail signed "Pause Cafe".
	 */
	public static function siteName(): string {
		$brand = trim( Settings::get( 'design_brand_name' ) );

		return '' !== $brand ? $brand : Mailer::fromName();
	}

	private static string $siteUrl = '';

	private static bool $https = false;

	public static function configure( string $siteUrl, bool $https ): void {
		self::$siteUrl = rtrim( trim( $siteUrl ), '/' );
		self::$https   = $https;
	}

	/**
	 * Where this site lives, for building links that leave it.
	 *
	 * Prefers the address in config.php, and that preference is the important
	 * part. Falling back on the Host header means trusting a value the caller
	 * sends: ask for a sign-in link with `Host: elsewhere.example` and the link
	 * emailed to the account holder points at elsewhere.example, carrying a
	 * working one-time token. They click it, and somebody else has their
	 * session. Pinning the address closes that; the fallback stays only so an
	 * install that has not been configured yet still functions.
	 *
	 * The scheme has three sources because a host behind a proxy sees none of
	 * the ones you would expect. Any of them saying HTTPS is believed; none of
	 * them can talk it down, because emitting https for an http site is a
	 * broken link while the reverse leaks a token.
	 */
	public static function baseUrl(): string {
		if ( '' !== self::$siteUrl ) {
			return self::$siteUrl;
		}

		$host = (string) ( $_SERVER['HTTP_HOST'] ?? '' );

		if ( '' === $host ) {
			return '';
		}

		$secure = self::$https
			|| ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
			|| 'https' === strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) );

		return ( $secure ? 'https' : 'http' ) . '://' . $host;
	}

	/** Whether the address was pinned, rather than taken from the request. */
	public static function urlIsPinned(): bool {
		return '' !== self::$siteUrl;
	}

	/**
	 * Confirmation to whoever placed the order.
	 */
	public static function orderPlaced( int $orderId ): Result {
		$order = Orders::find( $orderId );

		if ( ! $order ) {
			return Result::failed( 'none', 'No such order.' );
		}

		$lines = Orders::lines( $orderId );
		$body  = array();

		$body[] = 'Hello ' . $order['user_name'] . ',';
		$body[] = '';
		$body[] = 'Your order for ' . Schedule::formatDate( (string) $order['service_date'], 'l j F' ) . ' is confirmed.';
		$body[] = '';

		foreach ( $lines as $line ) {
			$who = '' !== $line['person_name'] ? $line['person_name'] : $order['user_name'];

			if ( '' !== $line['group_name'] ) {
				$who .= ' (' . $line['group_name'] . ')';
			}

			$body[] = '  ' . (int) $line['qty'] . ' x ' . $line['item_name'] .
				' — ' . $who . ' — ' . $line['location_name'];

			if ( '' !== ( $line['note'] ?? '' ) ) {
				$body[] = '      note: ' . $line['note'];
			}

			$extras = MenuFields::describeExtras( $line['extra_fields'] ?? '' );

			if ( '' !== $extras ) {
				$body[] = '      ' . $extras;
			}
		}

		$body[] = '';
		$body[] = 'Total: ' . Money::format( (int) $order['total_cents'] );
		$body[] = 'Paying by: ' . Payments::label( (string) $order['payment_method'] );

		if ( ! Orders::isPaid( $order ) ) {
			$body[] = '';
			$body[] = 'Please bring payment when you collect it.';
		}

		if ( '' !== $order['note'] ) {
			$body[] = '';
			$body[] = 'Your note: ' . $order['note'];
		}

		$url = self::baseUrl();

		if ( '' !== $url ) {
			$body[] = '';
			$body[] = 'See it here: ' . $url . '/orders/' . $orderId;
		}

		$body[] = '';
		$body[] = self::siteName();

		return Mailer::send(
			Message::make(
				(string) $order['user_email'],
				(string) $order['user_name'],
				'Order confirmed for ' . Schedule::formatDate( (string) $order['service_date'], 'j F' ),
				implode( "\n", $body )
			)
		);
	}

	/**
	 * Tells someone their order is off, and what happened to their money.
	 *
	 * Whether a refund went back is read from the ledger rather than guessed
	 * from the payment method — that is what actually moved, and it is what they
	 * will see on their statement.
	 */
	public static function orderCancelled( int $orderId ): Result {
		$order = Orders::find( $orderId );

		if ( ! $order ) {
			return Result::failed( 'none', 'No such order.' );
		}

		$refund = Orders::refundEntryFor( $orderId );
		$body   = array();

		$body[] = 'Hello ' . $order['user_name'] . ',';
		$body[] = '';
		$body[] = 'Your order for ' . Schedule::formatDate( (string) $order['service_date'], 'l j F' ) .
			' has been cancelled.';
		$body[] = '';

		foreach ( Orders::lines( $orderId ) as $line ) {
			$who = '' !== $line['person_name'] ? $line['person_name'] : $order['user_name'];

			$body[] = '  ' . (int) $line['qty'] . ' x ' . $line['item_name'] . ' — ' . $who;
		}

		$body[] = '';

		if ( $refund ) {
			$body[] = Money::format( (int) $refund['delta_cents'] ) . ' has gone back into your wallet.';
			$body[] = 'Your balance is now ' . Money::format( Wallet::balance( (int) $order['user_id'] ) ) . '.';
		} elseif ( Orders::isPaid( $order ) ) {
			// Paid outside the wallet, so the system has nothing to hand back.
			$body[] = 'You had already paid ' . Money::format( (int) $order['total_cents'] ) .
				' for this order. That was not taken from your wallet, so please speak to an organiser ' .
				'about getting it back.';
		} else {
			$body[] = 'Nothing had been charged for this order, so there is nothing to refund.';
		}

		$url = self::baseUrl();

		if ( '' !== $url ) {
			$body[] = '';
			$body[] = 'The order is still here: ' . $url . '/orders/' . $orderId;
		}

		$body[] = '';
		$body[] = self::siteName();

		return Mailer::send(
			Message::make(
				(string) $order['user_email'],
				(string) $order['user_name'],
				'Order cancelled for ' . Schedule::formatDate( (string) $order['service_date'], 'j F' ),
				implode( "\n", $body )
			)
		);
	}

	/**
	 * Tells someone a dish they have already ordered has been changed.
	 *
	 * @param array $person  From MenuChanges::affected() — user plus their lines.
	 * @param array $changes field => from/to.
	 */
	public static function orderedDishChanged( array $person, ?array $item, array $changes ): Result {
		$user  = $person['user'];
		$lines = $person['lines'];
		$when  = $lines ? (string) $lines[0]['service_date'] : '';

		$body   = array();
		$body[] = 'Hello ' . $user['name'] . ',';
		$body[] = '';
		$body[] = 'A dish you have already ordered' .
			( '' !== $when ? ' for ' . Schedule::formatDate( $when, 'l j F' ) : '' ) .
			' has been changed by the organisers.';
		$body[] = '';

		foreach ( $changes as $field => $change ) {
			$body[] = '  ' . ( MenuChanges::watched()[ $field ] ?? $field );
			$body[] = '    was: ' . self::describeValue( $field, $change['from'] );
			$body[] = '    now: ' . self::describeValue( $field, $change['to'] );
			$body[] = '';
		}

		$body[] = 'What you ordered is unchanged:';

		$charged = 0;

		foreach ( $lines as $line ) {
			$who = '' !== $line['person_name'] ? $line['person_name'] : $user['name'];

			if ( '' !== $line['group_name'] ) {
				$who .= ' (' . $line['group_name'] . ')';
			}

			$body[]   = '  ' . (int) $line['qty'] . ' x ' . $who;
			$charged += (int) $line['qty'] * (int) $line['unit_price_cents'];
		}

		$body[] = '';

		if ( isset( $changes['price_cents'] ) ) {
			// The price on an order is frozen at checkout, so a later change
			// cannot reach back and take more.
			$body[] = 'The price has changed, but you were charged ' . Money::format( $charged ) .
				' and nothing further will be taken.';
		} else {
			$body[] = 'You were charged ' . Money::format( $charged ) . '.';
		}

		$body[] = '';
		$body[] = 'If this does not work for you, speak to an organiser before ordering closes.';

		$url = self::baseUrl();

		if ( '' !== $url && $lines ) {
			$body[] = '';
			$body[] = 'Your order: ' . $url . '/orders/' . (int) $lines[0]['order_id'];
		}

		$body[] = '';
		$body[] = self::siteName();

		$name = $item ? (string) $item['name'] : ( $changes['name']['to'] ?? 'your order' );

		return Mailer::send(
			Message::make(
				(string) $user['email'],
				(string) $user['name'],
				'A dish you ordered has changed: ' . $name,
				implode( "\n", $body )
			)
		);
	}

	/**
	 * Renders a raw column value the way a customer would expect to read it.
	 */
	private static function describeValue( string $field, string $value ): string {
		if ( 'price_cents' === $field ) {
			return Money::format( (int) $value );
		}

		if ( 'service_date' === $field ) {
			return '' !== $value ? Schedule::formatDate( $value, 'l j F Y' ) : 'not set';
		}

		return '' !== $value ? $value : '(blank)';
	}

	/**
	 * Tells someone they can now order.
	 */
	public static function accountApproved( int $userId ): Result {
		$user = Users::find( $userId );

		if ( ! $user ) {
			return Result::failed( 'none', 'No such account.' );
		}

		$url  = self::baseUrl();
		$body = array(
			'Hello ' . $user['name'] . ',',
			'',
			'Your ' . self::siteName() . ' account has been approved — you can order lunch now.',
		);

		if ( '' !== $url ) {
			$body[] = '';
			$body[] = 'The menu is at ' . $url . '/';
		}

		$body[] = '';
		$body[] = self::siteName();

		return Mailer::send(
			Message::make(
				(string) $user['email'],
				(string) $user['name'],
				'Your ' . self::siteName() . ' account is ready',
				implode( "\n", $body )
			)
		);
	}

	/**
	 * The sign-in link itself.
	 *
	 * Sent as plain text with the URL on its own line. No button, no tracking
	 * wrapper: a link that signs somebody in should be one they can read before
	 * they click it.
	 */
	public static function signInLink( array $user, string $link, int $minutes ): Result {
		$body = array(
			'Hello ' . $user['name'] . ',',
			'',
			'Here is your link to sign in to ' . self::siteName() . ':',
			'',
			$link,
			'',
			'It works once, and stops working in ' . $minutes . ' minutes.',
			'',
			'If you did not ask for it, you can ignore this — nobody can get in without the link.',
			'',
			self::siteName(),
		);

		return Mailer::send(
			Message::make(
				(string) $user['email'],
				(string) $user['name'],
				'Your sign-in link for ' . self::siteName(),
				implode( "\n", $body )
			)
		);
	}

	/**
	 * Tells somebody their order was changed, and what happened to the money.
	 *
	 * Deliberately concrete about the amount and where it went. "Your order has
	 * been updated" invites a reply asking what that means; a figure and a
	 * destination does not.
	 */
	public static function orderChanged( int $orderId, int $wasCents ): Result {
		$order = Orders::find( $orderId );

		if ( ! $order ) {
			return Result::failed( 'none', 'No such order.' );
		}

		$user = Users::find( (int) $order['user_id'] );

		if ( ! $user ) {
			return Result::failed( 'none', 'No such account.' );
		}

		$now        = (int) $order['total_cents'];
		$difference = $now - $wasCents;
		$byWallet   = 'wallet' === (string) $order['payment_method'];

		$body = array(
			'Hello ' . $user['name'] . ',',
			'',
			'An organiser has changed your order for '
				. Schedule::formatDate( (string) $order['service_date'], 'l j F' ) . '.',
			'',
			'It is now:',
		);

		foreach ( Orders::lines( $orderId ) as $line ) {
			$body[] = '  ' . $line['qty'] . ' × ' . $line['item_name']
				. ( '' !== $line['person_name'] ? ' for ' . $line['person_name'] : '' )
				. '  ' . Money::format( (int) $line['unit_price_cents'] * (int) $line['qty'] );
		}

		$body[] = '';
		$body[] = 'Total: ' . Money::format( $now ) . ' (was ' . Money::format( $wasCents ) . ')';
		$body[] = '';

		if ( $difference < 0 ) {
			$body[] = $byWallet
				? Money::format( -$difference ) . ' has gone back into your wallet.'
				: Money::format( -$difference ) . ' less to pay on the day.';
		} elseif ( $difference > 0 ) {
			$body[] = $byWallet
				? Money::format( $difference ) . ' has come out of your wallet.'
				: Money::format( $difference ) . ' more to pay on the day.';
		}

		if ( $byWallet ) {
			$body[] = 'Your balance is now ' . Money::format( Wallet::balance( (int) $user['id'] ) ) . '.';
		}

		$body[] = '';
		$body[] = 'If this is not what you expected, reply to this email and an organiser will sort it out.';
		$body[] = '';
		$body[] = self::siteName();

		return Mailer::send(
			Message::make(
				(string) $user['email'],
				(string) $user['name'],
				'Your ' . self::siteName() . ' order has changed',
				implode( "\n", $body )
			)
		);
	}

	/**
	 * Tells the organisers somebody is waiting to be let in.
	 *
	 * @return Result[] One per organiser.
	 */
	public static function newRegistration( int $userId ): array {
		$user = Users::find( $userId );

		if ( ! $user ) {
			return array();
		}

		$url     = self::baseUrl();
		$results = array();

		foreach ( Users::all() as $candidate ) {
			if ( Users::ROLE_ADMIN !== $candidate['role'] ) {
				continue;
			}

			$body = array(
				'Hello ' . $candidate['name'] . ',',
				'',
				$user['name'] . ' (' . $user['email'] . ') has signed up and is waiting to be approved.',
			);

			if ( '' !== $user['group_name'] ) {
				$body[] = 'Group: ' . $user['group_name'];
			}

			if ( '' !== $url ) {
				$body[] = '';
				$body[] = 'Approve them at ' . $url . '/admin/users';
			}

			$body[] = '';
			$body[] = self::siteName();

			$results[] = Mailer::send(
				Message::make(
					(string) $candidate['email'],
					(string) $candidate['name'],
					'New sign-up: ' . $user['name'],
					implode( "\n", $body )
				)
			);
		}

		return $results;
	}

	/**
	 * The "does this work at all" message from the settings screen.
	 */
	public static function test( string $toEmail, string $toName ): Result {
		$body = array(
			'This is a test from ' . self::siteName() . '.',
			'',
			'Transport: ' . Mailer::selected()->label(),
			'From: ' . Mailer::fromEmail(),
			'Sent: ' . gmdate( 'c' ),
			'',
			'If you are reading this, email is working.',
		);

		return Mailer::send(
			Message::make( $toEmail, $toName, self::siteName() . ' test email', implode( "\n", $body ) )
		);
	}
}
