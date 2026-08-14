<?php

namespace PauseCafe\SignIn;

/**
 * Who an external provider says somebody is.
 *
 * Deliberately small. Whatever else an identity provider returns — groups,
 * roles, permissions, a picture — is ignored, because none of it should decide
 * anything here. Being an admin at Auth0 does not make you an organiser of the
 * lunch, and the provider has no business claiming it does.
 */
final class Profile {

	public function __construct(
		public readonly string $subject,
		public readonly string $email,
		public readonly bool $emailVerified,
		public readonly string $name = ''
	) {}

	/**
	 * Builds one from an OpenID Connect claim set.
	 *
	 * email_verified arrives as a boolean from most providers and as the string
	 * "true" from a few, so both are accepted — but nothing else is, and an
	 * absent claim counts as not verified.
	 */
	public static function fromClaims( array $claims ): self {
		$verified = $claims['email_verified'] ?? false;

		return new self(
			(string) ( $claims['sub'] ?? '' ),
			strtolower( trim( (string) ( $claims['email'] ?? '' ) ) ),
			true === $verified || 'true' === $verified || 1 === $verified || '1' === $verified,
			trim( (string) ( $claims['name'] ?? $claims['nickname'] ?? '' ) )
		);
	}

	public function isUsable(): bool {
		return '' !== $this->subject && '' !== $this->email;
	}
}
