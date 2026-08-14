<?php

namespace PauseCafe\SignIn;

/**
 * Just enough JSON Web Token to check an ID token, and no more.
 *
 * An ID token is the whole basis for believing who somebody is, so the checks
 * here are the security boundary of every provider that uses them. Three of
 * them exist because of specific, well-known ways this goes wrong:
 *
 *   - **Only RSA signatures are accepted.** A verifier that also accepts HMAC
 *     can be handed a token signed with the provider's *public* key as the HMAC
 *     secret, which is public. Accepting "none" is the same bug with less
 *     effort. The algorithm is read from the header, so the header does not get
 *     to choose the family.
 *
 *   - **The audience must contain our client id.** Otherwise a token minted for
 *     a different application at the same provider — one the attacker controls
 *     — signs in here.
 *
 *   - **The nonce must match the one we sent.** Otherwise a token captured from
 *     an earlier sign-in can be replayed.
 *
 * Everything is done with openssl_verify() against a key rebuilt from the
 * provider's JWKS, which means it can be tested end to end offline: generate a
 * key, sign a token, verify it. That is why this is written out rather than
 * leaning on the TLS channel alone.
 */
class Jwt {

	/** Signature algorithms this will verify, and the hash each one means. */
	private const ALGORITHMS = array(
		'RS256' => OPENSSL_ALGO_SHA256,
		'RS384' => OPENSSL_ALGO_SHA384,
		'RS512' => OPENSSL_ALGO_SHA512,
	);

	/** Seconds of clock difference tolerated between here and the provider. */
	private const LEEWAY = 120;

	public static function isSupported(): bool {
		return function_exists( 'openssl_verify' );
	}

	/**
	 * Reads the header without checking anything. Used only to find the key id.
	 */
	public static function header( string $jwt ): array {
		$parts = explode( '.', $jwt );

		return 3 === count( $parts ) ? self::decodeJson( $parts[0] ) : array();
	}

	/**
	 * Reads the claims without checking anything.
	 *
	 * Never use this to decide anything. It exists for logging and for error
	 * messages; verify() is the one that is allowed to be believed.
	 */
	public static function claimsUnverified( string $jwt ): array {
		$parts = explode( '.', $jwt );

		return 3 === count( $parts ) ? self::decodeJson( $parts[1] ) : array();
	}

	/**
	 * Checks a token and returns its claims.
	 *
	 * @param array $jwks     The provider's key set, as fetched from its JWKS URL.
	 * @param array $expect   issuer, audience, and optionally nonce.
	 * @param int   $now      Unix time, injectable so expiry can be tested.
	 *
	 * @throws \RuntimeException On anything at all being wrong.
	 */
	public static function verify( string $jwt, array $jwks, array $expect, int $now = 0 ): array {
		if ( ! self::isSupported() ) {
			throw new \RuntimeException( 'This server has no OpenSSL, so sign-in tokens cannot be checked.' );
		}

		$now   = $now ?: time();
		$parts = explode( '.', $jwt );

		if ( 3 !== count( $parts ) ) {
			throw new \RuntimeException( 'The sign-in token is malformed.' );
		}

		list( $encodedHeader, $encodedClaims, $encodedSignature ) = $parts;

		$header = self::decodeJson( $encodedHeader );
		$claims = self::decodeJson( $encodedClaims );

		if ( ! $header || ! $claims ) {
			throw new \RuntimeException( 'The sign-in token could not be read.' );
		}

		$algorithm = (string) ( $header['alg'] ?? '' );

		if ( ! isset( self::ALGORITHMS[ $algorithm ] ) ) {
			throw new \RuntimeException( 'The sign-in token is signed with ' . ( $algorithm ?: 'nothing' ) . ', which is not accepted.' );
		}

		$pem = self::keyFor( $jwks, (string) ( $header['kid'] ?? '' ) );

		$verified = openssl_verify(
			$encodedHeader . '.' . $encodedClaims,
			self::decode( $encodedSignature ),
			$pem,
			self::ALGORITHMS[ $algorithm ]
		);

		if ( 1 !== $verified ) {
			throw new \RuntimeException( 'The sign-in token’s signature is not valid.' );
		}

		self::checkClaims( $claims, $expect, $now );

		return $claims;
	}

	/**
	 * @throws \RuntimeException
	 */
	private static function checkClaims( array $claims, array $expect, int $now ): void {
		$issuer = (string) ( $expect['issuer'] ?? '' );

		if ( '' !== $issuer && ! hash_equals( $issuer, (string) ( $claims['iss'] ?? '' ) ) ) {
			throw new \RuntimeException( 'The sign-in token came from the wrong place.' );
		}

		$audience = (string) ( $expect['audience'] ?? '' );

		if ( '' !== $audience ) {
			$claimed = $claims['aud'] ?? '';
			$claimed = is_array( $claimed ) ? $claimed : array( $claimed );
			$matched = false;

			foreach ( $claimed as $one ) {
				if ( hash_equals( $audience, (string) $one ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				throw new \RuntimeException( 'The sign-in token was issued for a different application.' );
			}
		}

		if ( isset( $expect['nonce'] ) && '' !== (string) $expect['nonce'] ) {
			if ( ! hash_equals( (string) $expect['nonce'], (string) ( $claims['nonce'] ?? '' ) ) ) {
				throw new \RuntimeException( 'The sign-in token does not match this sign-in attempt.' );
			}
		}

		if ( isset( $claims['exp'] ) && $now > (int) $claims['exp'] + self::LEEWAY ) {
			throw new \RuntimeException( 'The sign-in token has expired. Please try again.' );
		}

		if ( isset( $claims['nbf'] ) && $now + self::LEEWAY < (int) $claims['nbf'] ) {
			throw new \RuntimeException( 'The sign-in token is not valid yet.' );
		}

		if ( isset( $claims['iat'] ) && $now + self::LEEWAY < (int) $claims['iat'] ) {
			throw new \RuntimeException( 'The sign-in token is dated in the future.' );
		}
	}

	/**
	 * Rebuilds a public key from the provider's key set.
	 *
	 * @throws \RuntimeException When no usable key matches.
	 */
	private static function keyFor( array $jwks, string $kid ): string {
		$keys = $jwks['keys'] ?? array();

		if ( ! is_array( $keys ) || ! $keys ) {
			throw new \RuntimeException( 'The sign-in provider published no signing keys.' );
		}

		$candidates = array();

		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) || 'RSA' !== ( $key['kty'] ?? '' ) ) {
				continue;
			}

			// A key marked for encryption must not be used to check signatures.
			if ( isset( $key['use'] ) && 'sig' !== $key['use'] ) {
				continue;
			}

			if ( '' !== $kid && isset( $key['kid'] ) && (string) $key['kid'] !== $kid ) {
				continue;
			}

			$candidates[] = $key;
		}

		/*
		 * With a kid in the header and no match, stop. Falling back to "try the
		 * others" would undo the point of key rotation.
		 */
		if ( ! $candidates ) {
			throw new \RuntimeException( 'The sign-in token was signed with a key this site does not know.' );
		}

		$key = $candidates[0];

		if ( ! isset( $key['n'], $key['e'] ) ) {
			throw new \RuntimeException( 'The sign-in provider’s key is incomplete.' );
		}

		return self::pemFromModulus( self::decode( (string) $key['n'] ), self::decode( (string) $key['e'] ) );
	}

	/**
	 * Wraps a raw RSA modulus and exponent as a PEM public key.
	 *
	 * JWKS gives the two numbers; OpenSSL wants DER. This is the standard
	 * SubjectPublicKeyInfo wrapper around a PKCS#1 RSAPublicKey, written by
	 * hand because pulling in an ASN.1 library for forty lines would be worse.
	 */
	private static function pemFromModulus( string $modulus, string $exponent ): string {
		$sequence = self::derSequence( self::derInteger( $modulus ) . self::derInteger( $exponent ) );

		// OID 1.2.840.113549.1.1.1 (rsaEncryption), then a NULL parameter.
		$algorithm = self::derSequence(
			"\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"
		);

		$bitString = "\x03" . self::derLength( strlen( $sequence ) + 1 ) . "\x00" . $sequence;

		$der = self::derSequence( $algorithm . $bitString );

		return "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split( base64_encode( $der ), 64, "\n" )
			. "-----END PUBLIC KEY-----\n";
	}

	private static function derLength( int $length ): string {
		if ( $length < 0x80 ) {
			return chr( $length );
		}

		$bytes = ltrim( pack( 'N', $length ), "\x00" );

		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	private static function derInteger( string $bytes ): string {
		$bytes = ltrim( $bytes, "\x00" );

		if ( '' === $bytes ) {
			$bytes = "\x00";
		}

		// DER integers are signed, so a leading bit of 1 needs a zero in front
		// or the number reads as negative.
		if ( ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes;
		}

		return "\x02" . self::derLength( strlen( $bytes ) ) . $bytes;
	}

	private static function derSequence( string $contents ): string {
		return "\x30" . self::derLength( strlen( $contents ) ) . $contents;
	}

	public static function decode( string $base64Url ): string {
		$padded = strtr( $base64Url, '-_', '+/' );
		$remain = strlen( $padded ) % 4;

		if ( $remain ) {
			$padded .= str_repeat( '=', 4 - $remain );
		}

		return (string) base64_decode( $padded, true );
	}

	public static function encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function decodeJson( string $segment ): array {
		$decoded = json_decode( self::decode( $segment ), true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
