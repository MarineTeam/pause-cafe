<?php

namespace PauseCafe\SignIn;

use PauseCafe\Identities;
use PauseCafe\Notifications;
use PauseCafe\Settings;

/**
 * Signing in somewhere else and coming back — the authorisation code flow.
 *
 * Auth0 and Supabase are both this, differing only in where their endpoints
 * live and how the answer is phrased, so they are subclasses of forty lines
 * rather than two separate integrations. Anything else that speaks OpenID
 * Connect — Google, Microsoft, Keycloak, Authentik — is another subclass of
 * about the same size.
 *
 * The flow, and why each piece is there:
 *
 *   1. **state** — random, kept in the session, compared on return. Without it
 *      an attacker can finish their own sign-in in your browser, leaving you
 *      logged in as them and ordering onto their wallet.
 *
 *   2. **nonce** — random, sent to the provider, required back inside the ID
 *      token. Stops a token captured once from being replayed.
 *
 *   3. **PKCE** — a random verifier, its SHA-256 sent up front, the verifier
 *      itself sent only with the code exchange. An intercepted code is then
 *      useless on its own.
 *
 * All three are one-shot: read out of the session and deleted before the code
 * is exchanged, so a replayed callback finds nothing to match against.
 */
abstract class OidcMethod implements Method {

	/** The claims come from a signed ID token, which is verified here. */
	protected const PROFILE_ID_TOKEN = 'id_token';

	/** The claims come from asking the provider, over TLS, who the token is for. */
	protected const PROFILE_USERINFO = 'userinfo';

	abstract public function id(): string;

	abstract public function label(): string;

	abstract public function describe(): string;

	/**
	 * Where this provider's endpoints are.
	 *
	 * @return array{authorize:string,token:string,jwks:string,userinfo:string,issuer:string}
	 */
	abstract protected function endpoints(): array;

	/** Which of the two ways of learning who somebody is this provider uses. */
	protected function profileSource(): string {
		return self::PROFILE_ID_TOKEN;
	}

	/**
	 * Whether this provider hands the state parameter back.
	 *
	 * Compliant ones do, and it is checked. One that does not is still safe
	 * here, because PKCE binds the callback to this session by itself: a code
	 * injected by somebody else was issued against their code challenge, and
	 * exchanging it with the verifier held in this session fails. Losing the
	 * state check costs a clearer error message, not the protection.
	 */
	protected function echoesState(): bool {
		return true;
	}

	protected function defaultScopes(): string {
		return 'openid email profile';
	}

	public function enabledByDefault(): bool {
		return false;
	}

	public function prompt(): string {
		return self::PROMPT_BUTTON;
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------ */

	public function settingKey( string $suffix ): string {
		return 'signin_' . $this->id() . '_' . $suffix;
	}

	public function clientId(): string {
		return trim( Settings::get( $this->settingKey( 'client_id' ) ) );
	}

	public function clientSecret(): string {
		return trim( Settings::get( $this->settingKey( 'client_secret' ) ) );
	}

	public function scopes(): string {
		$scopes = trim( Settings::get( $this->settingKey( 'scopes' ) ) );

		return '' !== $scopes ? $scopes : $this->defaultScopes();
	}

	/**
	 * Whether the settings are filled in.
	 *
	 * Deliberately local: this runs on every render of the login page, and
	 * asking the provider whether it is alive would put a network round trip —
	 * or a ten-second timeout, on a bad day — in front of everyone who visits.
	 * Whether the endpoints actually resolve is found out in start(), where
	 * there is somewhere sensible to report it.
	 */
	public function isConfigured(): bool {
		if ( ! Http::isSupported() ) {
			return false;
		}

		/*
		 * A method that proves identity with a signed token cannot do so
		 * without OpenSSL. Better to say so on the settings screen than to
		 * offer a button that fails at the last step of a sign-in.
		 */
		if ( self::PROFILE_ID_TOKEN === $this->profileSource() && ! Jwt::isSupported() ) {
			return false;
		}

		return '' !== $this->clientId() && '' !== $this->clientSecret();
	}

	public function requirement(): string {
		if ( ! Http::isSupported() ) {
			return 'This server has no curl, so it cannot talk to ' . $this->label() . '.';
		}

		if ( self::PROFILE_ID_TOKEN === $this->profileSource() && ! Jwt::isSupported() ) {
			return 'This server has no OpenSSL, so it cannot check the tokens ' . $this->label() . ' issues.';
		}

		return 'Needs the domain, client ID and client secret from your ' . $this->label() . ' application.';
	}

	/**
	 * Where the provider sends people back to.
	 *
	 * Shown on the settings screen because it has to be copied into the
	 * provider's own configuration, and a mismatch here is the single most
	 * common reason one of these does not work.
	 */
	public function redirectUri(): string {
		return Notifications::baseUrl() . '/auth/' . $this->id() . '/callback';
	}

	/* ---------------------------------------------------------------------
	 * The flow
	 * ------------------------------------------------------------------ */

	public function start( array $input ): Outcome {
		if ( ! $this->isConfigured() ) {
			return Outcome::failure( $this->label() . ' is not set up yet.' );
		}

		$endpoints = $this->endpoints();

		if ( '' === $endpoints['authorize'] ) {
			return Outcome::failure( $this->label() . ' could not be reached. Please try again shortly.' );
		}

		$state    = $this->random();
		$nonce    = $this->random();
		$verifier = $this->random();

		$_SESSION['oidc'][ $this->id() ] = array(
			'state'    => $state,
			'nonce'    => $nonce,
			'verifier' => $verifier,
			'at'       => time(),
		);

		$parameters = array(
			'response_type'         => 'code',
			'client_id'             => $this->clientId(),
			'redirect_uri'          => $this->redirectUri(),
			'scope'                 => $this->scopes(),
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => Jwt::encode( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 'S256',
		);

		$separator = str_contains( $endpoints['authorize'], '?' ) ? '&' : '?';

		return Outcome::redirect( $endpoints['authorize'] . $separator . http_build_query( $parameters ) );
	}

	public function finish( array $input ): Outcome {
		$stored = $_SESSION['oidc'][ $this->id() ] ?? null;

		// One shot. Whatever happens below, this attempt is now spent.
		unset( $_SESSION['oidc'][ $this->id() ] );

		if ( ! is_array( $stored ) ) {
			return Outcome::failure( 'That sign-in took too long, or was started in another window. Please try again.' );
		}

		// Fifteen minutes is generous for a redirect and a password box.
		if ( time() - (int) ( $stored['at'] ?? 0 ) > 900 ) {
			return Outcome::failure( 'That sign-in took too long. Please try again.' );
		}

		if ( '' !== (string) ( $input['error'] ?? '' ) ) {
			return Outcome::failure( $this->describeProviderError( $input ) );
		}

		if ( $this->echoesState() && ! hash_equals( (string) $stored['state'], (string) ( $input['state'] ?? '' ) ) ) {
			return Outcome::failure( 'That sign-in could not be matched to this browser. Please try again.' );
		}

		$code = (string) ( $input['code'] ?? '' );

		if ( '' === $code ) {
			return Outcome::failure( $this->label() . ' did not send a sign-in code.' );
		}

		try {
			$tokens  = $this->exchange( $code, (string) $stored['verifier'] );
			$profile = self::PROFILE_USERINFO === $this->profileSource()
				? $this->profileFromUserinfo( $tokens )
				: $this->profileFromIdToken( $tokens, (string) $stored['nonce'] );
		} catch ( \RuntimeException $e ) {
			return Outcome::failure( $e->getMessage() );
		}

		return Identities::resolve( $this->id(), $profile );
	}

	/**
	 * Trades the code for tokens.
	 *
	 * @throws \RuntimeException
	 */
	protected function exchange( string $code, string $verifier ): array {
		$endpoints = $this->endpoints();

		$response = Http::postForm(
			$endpoints['token'],
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->redirectUri(),
				'client_id'     => $this->clientId(),
				'client_secret' => $this->clientSecret(),
				'code_verifier' => $verifier,
			)
		);

		if ( 200 !== $response['status'] ) {
			/*
			 * The provider's own wording is often the only clue an organiser
			 * gets — "redirect_uri mismatch" saves an afternoon. It is shown as
			 * text, never as markup.
			 */
			$detail = (string) ( $response['json']['error_description'] ?? $response['json']['error'] ?? $response['error'] );

			throw new \RuntimeException(
				$this->label() . ' refused the sign-in' . ( '' !== $detail ? ': ' . $detail : '.' )
			);
		}

		return $response['json'];
	}

	/**
	 * @throws \RuntimeException
	 */
	protected function profileFromIdToken( array $tokens, string $nonce ): Profile {
		$idToken = (string) ( $tokens['id_token'] ?? '' );

		if ( '' === $idToken ) {
			throw new \RuntimeException( $this->label() . ' did not return an identity token.' );
		}

		$endpoints = $this->endpoints();
		$jwks      = Http::getJson( $endpoints['jwks'] );

		if ( 200 !== $jwks['status'] ) {
			throw new \RuntimeException( 'Could not fetch the signing keys from ' . $this->label() . '.' );
		}

		$claims = Jwt::verify(
			$idToken,
			$jwks['json'],
			array(
				'issuer'   => $endpoints['issuer'],
				'audience' => $this->clientId(),
				'nonce'    => $nonce,
			)
		);

		/*
		 * Some providers leave email out of the ID token when the scope was
		 * thin. Ask directly rather than failing.
		 */
		if ( '' === (string) ( $claims['email'] ?? '' ) && '' !== $endpoints['userinfo'] ) {
			$claims = array_merge( $claims, $this->userinfo( $tokens, $endpoints['userinfo'] ) );
		}

		return Profile::fromClaims( $claims );
	}

	/**
	 * @throws \RuntimeException
	 */
	protected function profileFromUserinfo( array $tokens ): Profile {
		$endpoints = $this->endpoints();

		return Profile::fromClaims( $this->userinfo( $tokens, $endpoints['userinfo'] ) );
	}

	/**
	 * @throws \RuntimeException
	 */
	protected function userinfo( array $tokens, string $url ): array {
		$accessToken = (string) ( $tokens['access_token'] ?? '' );

		if ( '' === $accessToken || '' === $url ) {
			throw new \RuntimeException( $this->label() . ' did not say who signed in.' );
		}

		$response = Http::getJson( $url, array( 'Authorization: Bearer ' . $accessToken ) );

		if ( 200 !== $response['status'] ) {
			throw new \RuntimeException( 'Could not read your details from ' . $this->label() . '.' );
		}

		return $response['json'];
	}

	private function describeProviderError( array $input ): string {
		$error = (string) ( $input['error'] ?? '' );

		if ( 'access_denied' === $error ) {
			return 'That sign-in was cancelled.';
		}

		$detail = (string) ( $input['error_description'] ?? $error );

		return $this->label() . ' could not sign you in: ' . $detail;
	}

	protected function random(): string {
		return Jwt::encode( random_bytes( 32 ) );
	}

	/**
	 * Reads the endpoints a standards-compliant provider publishes.
	 *
	 * @return array{authorize:string,token:string,jwks:string,userinfo:string,issuer:string}
	 */
	protected function discover( string $issuer ): array {
		$blank = array(
			'authorize' => '',
			'token'     => '',
			'jwks'      => '',
			'userinfo'  => '',
			'issuer'    => $issuer,
		);

		if ( '' === $issuer ) {
			return $blank;
		}

		$response = Http::getJson( rtrim( $issuer, '/' ) . '/.well-known/openid-configuration' );

		if ( 200 !== $response['status'] ) {
			return $blank;
		}

		$document = $response['json'];

		return array(
			'authorize' => (string) ( $document['authorization_endpoint'] ?? '' ),
			'token'     => (string) ( $document['token_endpoint'] ?? '' ),
			'jwks'      => (string) ( $document['jwks_uri'] ?? '' ),
			'userinfo'  => (string) ( $document['userinfo_endpoint'] ?? '' ),
			// The issuer the provider says it is, which is what an ID token
			// will claim -- not necessarily the URL it was reached at.
			'issuer'    => (string) ( $document['issuer'] ?? $issuer ),
		);
	}
}
