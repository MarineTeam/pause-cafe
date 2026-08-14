<?php

namespace PauseCafe\SignIn;

/**
 * One way of proving who you are.
 *
 * Sign-in has two halves, and which half does the work varies. A password
 * decides everything in start(); a magic link sends an email in start() and
 * decides in finish() when the link is clicked; an identity provider redirects
 * in start() and decides in finish() when the browser comes back. A method that
 * finishes in one step returns a failure from finish(), and vice versa.
 *
 * Whatever a method decides, it never decides whether someone may order. That
 * stays with the organisers — see Identities::resolve().
 */
interface Method {

	/** Stable key. Used in settings keys, URLs and the identities table. */
	public function id(): string;

	/** Shown to whoever is signing in. */
	public function label(): string;

	/** One line for the organiser, explaining what this is. */
	public function describe(): string;

	/** Whether it should be on before anyone has said otherwise. */
	public function enabledByDefault(): bool;

	/** Whether it has everything it needs to work. */
	public function isConfigured(): bool;

	/** What is missing, when isConfigured() is false. */
	public function requirement(): string;

	/**
	 * Settings this method needs, as key => array{label,type,help,placeholder}.
	 *
	 * Keys are stored verbatim in the settings table, so they carry the method
	 * id as a prefix by convention. Type is text, password or url; password is
	 * masked on the settings screen and never echoed back.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function fields(): array;

	/**
	 * How the login page offers it: a form asking for something, or a button
	 * that leaves the site.
	 */
	public function prompt(): string;

	public const PROMPT_PASSWORD = 'password';
	public const PROMPT_EMAIL    = 'email';
	public const PROMPT_BUTTON   = 'button';

	/** Begin. $input is the submitted form, already trimmed where sensible. */
	public function start( array $input ): Outcome;

	/** Complete. $input is the query string coming back, or the clicked link. */
	public function finish( array $input ): Outcome;
}
