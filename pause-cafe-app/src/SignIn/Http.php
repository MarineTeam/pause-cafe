<?php

namespace PauseCafe\SignIn;

/**
 * The small amount of HTTP the identity providers need.
 *
 * Everything here talks to a provider about who somebody is, so TLS
 * verification is not optional and is set explicitly rather than left to
 * whatever the host's curl defaults happen to be. A misconfigured shared host
 * that quietly disabled peer verification would turn every one of these calls
 * into something a network attacker can answer.
 *
 * Responses are memoised for the length of one request only. Sign-ins here
 * happen a few times a day, so a fetch per sign-in costs nothing worth the
 * trouble of a cache that can go stale across a key rotation.
 */
class Http {

	/** @var array<string,array> */
	private static array $memo = array();

	public static function isSupported(): bool {
		return function_exists( 'curl_init' );
	}

	/**
	 * @return array{status:int,json:array,body:string,error:string}
	 */
	public static function getJson( string $url, array $headers = array(), int $timeout = 10 ): array {
		$key = 'GET ' . $url . ' ' . implode( '|', $headers );

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$result = self::send( $url, null, $headers, $timeout );

		// Only successes are worth remembering; a blip should be retryable.
		if ( 200 === $result['status'] ) {
			self::$memo[ $key ] = $result;
		}

		return $result;
	}

	/**
	 * @return array{status:int,json:array,body:string,error:string}
	 */
	public static function postForm( string $url, array $fields, array $headers = array(), int $timeout = 10 ): array {
		return self::send( $url, http_build_query( $fields ), $headers, $timeout );
	}

	/**
	 * @return array{status:int,json:array,body:string,error:string}
	 */
	public static function postJson( string $url, array $payload, array $headers = array(), int $timeout = 10 ): array {
		return self::send(
			$url,
			(string) json_encode( $payload ),
			array_merge( $headers, array( 'Content-Type: application/json' ) ),
			$timeout,
			false
		);
	}

	/**
	 * @return array{status:int,json:array,body:string,error:string}
	 */
	private static function send( string $url, ?string $body, array $headers, int $timeout, bool $formEncoded = true ): array {
		$failure = static fn( string $message ): array => array(
			'status' => 0,
			'json'   => array(),
			'body'   => '',
			'error'  => $message,
		);

		if ( ! self::isSupported() ) {
			return $failure( 'This server has no curl, so it cannot talk to a sign-in provider.' );
		}

		/*
		 * Client secrets and authorisation codes travel in these requests. Over
		 * plain HTTP they would travel in the clear, so refuse rather than
		 * downgrade.
		 */
		if ( ! str_starts_with( strtolower( $url ), 'https://' ) ) {
			return $failure( 'Sign-in providers must be reached over HTTPS.' );
		}

		$curl = curl_init( $url );

		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER     => array_merge( array( 'Accept: application/json' ), $headers ),
		);

		if ( null !== $body ) {
			$options[ CURLOPT_POST ]       = true;
			$options[ CURLOPT_POSTFIELDS ] = $body;

			if ( $formEncoded ) {
				$options[ CURLOPT_HTTPHEADER ] = array_merge(
					$options[ CURLOPT_HTTPHEADER ],
					array( 'Content-Type: application/x-www-form-urlencoded' )
				);
			}
		}

		curl_setopt_array( $curl, $options );

		$response = curl_exec( $curl );
		$status   = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error    = (string) curl_error( $curl );

		curl_close( $curl );

		if ( false === $response ) {
			return $failure( '' !== $error ? $error : 'The sign-in provider could not be reached.' );
		}

		$decoded = json_decode( (string) $response, true );

		return array(
			'status' => $status,
			'json'   => is_array( $decoded ) ? $decoded : array(),
			'body'   => (string) $response,
			'error'  => '',
		);
	}

	/** Test seam. */
	public static function forget(): void {
		self::$memo = array();
	}
}
