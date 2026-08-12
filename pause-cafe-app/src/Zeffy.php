<?php

namespace PauseCafe;

/**
 * Zeffy top-ups.
 *
 * Zeffy's public API is read-only -- it cannot take a payment on our behalf --
 * but it does POST a webhook when a payment completes. That is enough: members
 * pay through a Zeffy form, the webhook arrives, and the matching wallet is
 * credited. Payments are matched to accounts by email address.
 *
 * The exact webhook payload shape is not something this code can verify without
 * a live account, so extraction is deliberately tolerant of several plausible
 * field names and every raw payload is logged. Check data/zeffy.log after the
 * first real payment and tighten `extract()` to the fields actually sent.
 */
class Zeffy {

	private static string $secret = '';

	private static string $apiKey = '';

	private static string $logPath = '';

	public static function configure( string $secret, string $apiKey, string $logPath ): void {
		self::$secret  = $secret;
		self::$apiKey  = $apiKey;
		self::$logPath = $logPath;
	}

	public static function isConfigured(): bool {
		return '' !== self::$secret;
	}

	/**
	 * Shared-secret check. Zeffy is configured to send the secret either as the
	 * `X-Zeffy-Secret` header or as `?key=` on the webhook URL.
	 */
	public static function authorise( array $headers, array $query ): bool {
		if ( '' === self::$secret ) {
			return false;
		}

		$candidates = array(
			$headers['X-Zeffy-Secret'] ?? '',
			$headers['x-zeffy-secret'] ?? '',
			$query['key'] ?? '',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && hash_equals( self::$secret, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	public static function log( string $label, $payload ): void {
		if ( '' === self::$logPath ) {
			return;
		}

		$directory = dirname( self::$logPath );

		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0775, true );
		}

		$line = gmdate( 'c' ) . ' ' . $label . ' ' . json_encode( $payload ) . PHP_EOL;

		file_put_contents( self::$logPath, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Pulls email, amount in cents and a stable payment ID out of a payload.
	 *
	 * @return array{email:string,cents:int,reference:string,name:string}|null
	 */
	public static function extract( array $payload ): ?array {
		// Some providers wrap the useful part in "data" or "payment".
		foreach ( array( 'data', 'payment', 'object' ) as $wrapper ) {
			if ( isset( $payload[ $wrapper ] ) && is_array( $payload[ $wrapper ] ) ) {
				$payload = array_merge( $payload, $payload[ $wrapper ] );
			}
		}

		$email = self::firstString(
			$payload,
			array( 'email', 'donorEmail', 'donor_email', 'payerEmail', 'contactEmail', 'buyerEmail' )
		);

		if ( '' === $email && isset( $payload['contact'] ) && is_array( $payload['contact'] ) ) {
			$email = self::firstString( $payload['contact'], array( 'email' ) );
		}

		if ( '' === $email ) {
			return null;
		}

		$reference = self::firstString(
			$payload,
			array( 'id', 'paymentId', 'payment_id', 'transactionId', 'reference' )
		);

		if ( '' === $reference ) {
			// Without a stable ID a redelivery would credit twice, so fall back to
			// a hash of the payload rather than skipping the idempotency guard.
			$reference = 'hash:' . substr( hash( 'sha256', json_encode( $payload ) ), 0, 32 );
		}

		$cents = self::amountInCents( $payload );

		if ( $cents <= 0 ) {
			return null;
		}

		return array(
			'email'     => Users::normaliseEmail( $email ),
			'cents'     => $cents,
			'reference' => $reference,
			'name'      => self::firstString( $payload, array( 'name', 'donorName', 'firstName', 'fullName' ) ),
		);
	}

	private static function firstString( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				return trim( (string) $payload[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Amounts arrive either already in cents or as a decimal. A field named
	 * *Cents is trusted as-is; anything else is treated as a decimal amount.
	 */
	private static function amountInCents( array $payload ): int {
		foreach ( array( 'amountInCents', 'amount_cents', 'totalCents' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_numeric( $payload[ $key ] ) ) {
				return (int) $payload[ $key ];
			}
		}

		foreach ( array( 'amount', 'total', 'netAmount', 'grossAmount' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_numeric( $payload[ $key ] ) ) {
				return (int) round( ( (float) $payload[ $key ] ) * 100 );
			}
		}

		return 0;
	}

	/**
	 * Credits the matching wallet.
	 *
	 * @return array{status:string,message:string}
	 */
	public static function applyPayment( array $payload ): array {
		$details = self::extract( $payload );

		if ( ! $details ) {
			self::log( 'unparsed', $payload );

			return array(
				'status'  => 'ignored',
				'message' => 'Could not read an email and amount from this payload.',
			);
		}

		$user = Users::findByEmail( $details['email'] );

		if ( ! $user ) {
			// Deliberately not auto-creating an account: ordering is gated on
			// admin approval, and a payment is not approval.
			self::log( 'unmatched', $details );

			return array(
				'status'  => 'unmatched',
				'message' => 'No account for ' . $details['email'] . '. Credit it by hand once the account exists.',
			);
		}

		if ( Wallet::hasReference( Wallet::KIND_ZEFFY, $details['reference'] ) ) {
			return array(
				'status'  => 'duplicate',
				'message' => 'Already recorded.',
			);
		}

		try {
			Wallet::credit(
				(int) $user['id'],
				$details['cents'],
				Wallet::KIND_ZEFFY,
				'Zeffy payment',
				$details['reference']
			);
		} catch ( \RuntimeException $e ) {
			return array(
				'status'  => 'duplicate',
				'message' => $e->getMessage(),
			);
		}

		self::log( 'credited', $details );

		return array(
			'status'  => 'credited',
			'message' => Money::format( $details['cents'] ) . ' credited to ' . $details['email'] . '.',
		);
	}

	/**
	 * Reconciliation against the read-only API. Returns what it did per payment
	 * so an admin can see it rather than it happening silently.
	 *
	 * @return array{ok:bool,message:string,results:array[]}
	 */
	public static function reconcile( string $endpoint = 'https://api.zeffy.com/v1/payments' ): array {
		if ( '' === self::$apiKey ) {
			return array(
				'ok'      => false,
				'message' => 'No Zeffy API key is configured.',
				'results' => array(),
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return array(
				'ok'      => false,
				'message' => 'This server has no cURL, so the API cannot be reached.',
				'results' => array(),
			);
		}

		$curl = curl_init( $endpoint );

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Bearer ' . self::$apiKey,
					'Accept: application/json',
				),
			)
		);

		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $curl );

		curl_close( $curl );

		if ( false === $body || '' !== $error ) {
			return array(
				'ok'      => false,
				'message' => 'Could not reach Zeffy: ' . $error,
				'results' => array(),
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			return array(
				'ok'      => false,
				'message' => 'Zeffy replied with HTTP ' . $status . '.',
				'results' => array(),
			);
		}

		$decoded = json_decode( (string) $body, true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'      => false,
				'message' => 'Zeffy sent something that was not JSON.',
				'results' => array(),
			);
		}

		$payments = $decoded['data'] ?? ( $decoded['payments'] ?? $decoded );
		$results  = array();

		if ( is_array( $payments ) ) {
			foreach ( $payments as $payment ) {
				if ( is_array( $payment ) ) {
					$results[] = self::applyPayment( $payment );
				}
			}
		}

		$credited = count( array_filter( $results, static fn( $r ) => 'credited' === $r['status'] ) );

		return array(
			'ok'      => true,
			'message' => $credited . ' of ' . count( $results ) . ' payments were new and have been credited.',
			'results' => $results,
		);
	}
}
