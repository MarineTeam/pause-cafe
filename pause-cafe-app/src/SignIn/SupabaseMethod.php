<?php

namespace PauseCafe\SignIn;

use PauseCafe\Settings;

/**
 * Supabase Auth.
 *
 * The odd one out. Supabase is not an OpenID Connect provider — it is a hosted
 * auth service meant to be driven from its own browser SDK — so three things
 * differ from Auth0 and are overridden below:
 *
 *   - **No client secret.** Requests carry the project's anon key in an apikey
 *     header instead.
 *   - **A different code exchange.** JSON rather than a form, `auth_code`
 *     rather than `code`, and the grant type in the query string.
 *   - **No ID token to verify.** The token response already contains the user,
 *     read over a TLS connection to the project, which is the same basis the
 *     official server-side helpers use.
 *
 * Supabase does not echo a state parameter, so the callback is bound to the
 * session by PKCE alone — see OidcMethod::echoesState() for why that is enough.
 *
 * Sign-in goes through whichever social provider the project has configured;
 * Supabase itself only brokers it.
 */
class SupabaseMethod extends OidcMethod {

	public function id(): string {
		return 'supabase';
	}

	public function label(): string {
		return 'Supabase';
	}

	public function describe(): string {
		return 'Members sign in through a Supabase project, which brokers Google, GitHub and the rest.';
	}

	public function fields(): array {
		return array(
			$this->settingKey( 'url' )      => array(
				'label'       => 'Project URL',
				'type'        => 'text',
				'help'        => 'From Supabase, Settings then API — for example https://abcdefgh.supabase.co',
				'placeholder' => 'https://abcdefgh.supabase.co',
			),
			$this->settingKey( 'anon_key' ) => array(
				'label'       => 'Anon key',
				'type'        => 'password',
				'help'        => 'The public anon key from the same screen. Not the service role key — that one must never leave Supabase.',
				'placeholder' => '',
			),
			$this->settingKey( 'provider' ) => array(
				'label'       => 'Sign in with',
				'type'        => 'text',
				'help'        => 'The social provider enabled in your Supabase project: google, github, azure, apple.',
				'placeholder' => 'google',
			),
		);
	}

	public function projectUrl(): string {
		$url = trim( Settings::get( $this->settingKey( 'url' ) ) );

		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		return rtrim( $url, '/' );
	}

	public function anonKey(): string {
		return trim( Settings::get( $this->settingKey( 'anon_key' ) ) );
	}

	public function provider(): string {
		$provider = strtolower( trim( Settings::get( $this->settingKey( 'provider' ) ) ) );

		// Goes into a URL, so keep it to something that plainly cannot escape.
		$provider = preg_replace( '/[^a-z0-9_-]/', '', $provider ) ?? '';

		return '' !== $provider ? $provider : 'google';
	}

	public function isConfigured(): bool {
		return Http::isSupported() && '' !== $this->projectUrl() && '' !== $this->anonKey();
	}

	public function requirement(): string {
		if ( ! Http::isSupported() ) {
			return 'This server has no curl, so it cannot talk to Supabase.';
		}

		return 'Needs the project URL and anon key from your Supabase project.';
	}

	protected function profileSource(): string {
		return self::PROFILE_USERINFO;
	}

	protected function echoesState(): bool {
		return false;
	}

	protected function endpoints(): array {
		$base = $this->projectUrl();

		if ( '' === $base ) {
			return array(
				'authorize' => '',
				'token'     => '',
				'jwks'      => '',
				'userinfo'  => '',
				'issuer'    => '',
			);
		}

		return array(
			'authorize' => $base . '/auth/v1/authorize',
			'token'     => $base . '/auth/v1/token?grant_type=pkce',
			'jwks'      => '',
			'userinfo'  => $base . '/auth/v1/user',
			'issuer'    => $base,
		);
	}

	/**
	 * Supabase takes a different set of query parameters from a standard
	 * provider, so the authorisation redirect is built here rather than
	 * inherited.
	 */
	public function start( array $input ): Outcome {
		if ( ! $this->isConfigured() ) {
			return Outcome::failure( 'Supabase is not set up yet.' );
		}

		$verifier = $this->random();

		$_SESSION['oidc'][ $this->id() ] = array(
			'state'    => $this->random(),
			'nonce'    => '',
			'verifier' => $verifier,
			'at'       => time(),
		);

		$parameters = array(
			'provider'              => $this->provider(),
			'redirect_to'           => $this->redirectUri(),
			'code_challenge'        => Jwt::encode( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 's256',
		);

		return Outcome::redirect(
			$this->endpoints()['authorize'] . '?' . http_build_query( $parameters )
		);
	}

	/**
	 * @throws \RuntimeException
	 */
	protected function exchange( string $code, string $verifier ): array {
		$response = Http::postJson(
			$this->endpoints()['token'],
			array(
				'auth_code'     => $code,
				'code_verifier' => $verifier,
			),
			array( 'apikey: ' . $this->anonKey() )
		);

		if ( 200 !== $response['status'] ) {
			$detail = (string) ( $response['json']['error_description']
				?? $response['json']['msg']
				?? $response['json']['error']
				?? $response['error'] );

			throw new \RuntimeException( 'Supabase refused the sign-in' . ( '' !== $detail ? ': ' . $detail : '.' ) );
		}

		return $response['json'];
	}

	/**
	 * The user comes back with the tokens, so there is nothing further to ask.
	 *
	 * @throws \RuntimeException
	 */
	protected function profileFromUserinfo( array $tokens ): Profile {
		$user = $tokens['user'] ?? null;

		if ( ! is_array( $user ) ) {
			// Older projects answer the token call without the user attached.
			$user = $this->userinfo( $tokens, $this->endpoints()['userinfo'] );
		}

		return new Profile(
			(string) ( $user['id'] ?? '' ),
			strtolower( trim( (string) ( $user['email'] ?? '' ) ) ),
			// Supabase records a timestamp rather than a flag; its presence is
			// the confirmation.
			'' !== (string) ( $user['email_confirmed_at'] ?? $user['confirmed_at'] ?? '' ),
			trim( (string) ( $user['user_metadata']['full_name'] ?? $user['user_metadata']['name'] ?? '' ) )
		);
	}

	/** Supabase wants the anon key on the user call as well as a bearer token. */
	protected function userinfo( array $tokens, string $url ): array {
		$accessToken = (string) ( $tokens['access_token'] ?? '' );

		if ( '' === $accessToken || '' === $url ) {
			throw new \RuntimeException( 'Supabase did not say who signed in.' );
		}

		$response = Http::getJson(
			$url,
			array(
				'Authorization: Bearer ' . $accessToken,
				'apikey: ' . $this->anonKey(),
			)
		);

		if ( 200 !== $response['status'] ) {
			throw new \RuntimeException( 'Could not read your details from Supabase.' );
		}

		return $response['json'];
	}
}
