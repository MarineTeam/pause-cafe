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

	public static function siteName(): string {
		return Mailer::fromName();
	}

	private static function baseUrl(): string {
		$scheme = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ? 'https' : 'http';
		$host   = (string) ( $_SERVER['HTTP_HOST'] ?? '' );

		return '' !== $host ? $scheme . '://' . $host : '';
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
