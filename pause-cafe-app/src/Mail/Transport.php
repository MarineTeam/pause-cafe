<?php

namespace PauseCafe\Mail;

/**
 * A way of getting an email out of the building.
 *
 * Implement this and register it with `Mailer::register()`. The settings screen
 * renders whatever `configFields()` returns, so a new transport gets its own
 * form without that screen changing.
 */
interface Transport {

	/** Stable identifier, stored in settings. */
	public function id(): string;

	/** Shown in the settings screen. */
	public function label(): string;

	/** One line under the label. */
	public function description(): string;

	/**
	 * Whether this transport has everything it needs to try sending.
	 */
	public function isConfigured(): bool;

	/**
	 * Settings this transport needs, keyed by setting name.
	 *
	 * Each value is [ label, type, help ] where type is text, password, number
	 * or select. A select adds a fourth element: an options map.
	 *
	 * @return array<string,array>
	 */
	public function configFields(): array;

	/**
	 * Attempts delivery.
	 *
	 * Must not throw. A transport that cannot reach its server returns a failed
	 * Result so the caller can fall back rather than take the request down.
	 */
	public function send( Message $message, string $fromName, string $fromEmail ): Result;
}
