<?php

namespace PauseCafe\SignIn;

use PauseCafe\Settings;

/**
 * Auth0.
 *
 * A textbook OpenID Connect provider, so this is only the address of the
 * tenant. Everything else — where to send people, where to swap the code,
 * which keys sign the tokens — is read from the discovery document, which means
 * a tenant that moves an endpoint or rotates a key needs nothing changed here.
 *
 * The same class works for any compliant provider. Google, Microsoft Entra,
 * Keycloak and Authentik would each be a copy of this file with a different
 * label and a different way of turning a setting into an issuer URL.
 */
class Auth0Method extends OidcMethod {

	public function id(): string {
		return 'auth0';
	}

	public function label(): string {
		return 'Auth0';
	}

	public function describe(): string {
		return 'Members sign in at your Auth0 tenant. Suits a church already using Auth0 for something else.';
	}

	public function fields(): array {
		return array(
			$this->settingKey( 'domain' )        => array(
				'label'       => 'Auth0 domain',
				'type'        => 'text',
				'help'        => 'From your Auth0 application, without https:// — for example your-church.eu.auth0.com',
				'placeholder' => 'your-church.eu.auth0.com',
			),
			$this->settingKey( 'client_id' )     => array(
				'label'       => 'Client ID',
				'type'        => 'text',
				'help'        => 'From the same application.',
				'placeholder' => '',
			),
			$this->settingKey( 'client_secret' ) => array(
				'label'       => 'Client secret',
				'type'        => 'password',
				'help'        => 'Kept on this server and never shown again once saved.',
				'placeholder' => '',
			),
			$this->settingKey( 'scopes' )        => array(
				'label'       => 'Scopes',
				'type'        => 'text',
				'help'        => 'Leave blank unless you know you need something else.',
				'placeholder' => 'openid email profile',
			),
		);
	}

	public function domain(): string {
		$domain = trim( Settings::get( $this->settingKey( 'domain' ) ) );

		// Organisers paste the whole URL about half the time.
		$domain = preg_replace( '#^https?://#i', '', $domain ) ?? '';

		return rtrim( $domain, '/' );
	}

	public function isConfigured(): bool {
		return '' !== $this->domain() && parent::isConfigured();
	}

	protected function endpoints(): array {
		$domain = $this->domain();

		if ( '' === $domain ) {
			return array(
				'authorize' => '',
				'token'     => '',
				'jwks'      => '',
				'userinfo'  => '',
				'issuer'    => '',
			);
		}

		return $this->discover( 'https://' . $domain );
	}
}
