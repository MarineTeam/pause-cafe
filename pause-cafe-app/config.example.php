<?php
/**
 * Copy to config.php and edit. config.php is gitignored.
 */

return array(
	// Where the SQLite database lives. Must be writable and, ideally, outside
	// the web root. Back it up by copying this one file.
	'database'    => __DIR__ . '/data/pause-cafe.sqlite',

	// Shown in the header and in emails.
	'site_name'   => 'Pause Cafe',

	// Local timezone. Every cutoff is worked out in this zone.
	'timezone'    => 'America/Vancouver',

	// Currency symbol used throughout. Amounts are stored as integer cents.
	'currency'    => '$',

	// Set true only behind HTTPS: marks the session cookie secure.
	'https'       => false,

	/*
	 * The site's own address, with no trailing slash — https://lunch.example.org
	 *
	 * Set this. Every link the app puts in an email is built from it, and with
	 * it blank the address is taken from the request's Host header instead,
	 * which the caller controls: somebody can ask for a sign-in link while
	 * claiming to be another host, and the link emailed to the account holder
	 * will point there, carrying a working one-time token.
	 *
	 * It also fixes the scheme behind a proxy, where PHP often cannot tell that
	 * the visitor arrived over HTTPS.
	 */
	'site_url'    => '',

	/*
	 * Zeffy sends a POST to /webhook/zeffy when a payment completes. Set a long
	 * random string here and configure the same one in Zeffy so the endpoint can
	 * tell a real callback from anyone who guesses the URL.
	 */
	'zeffy_secret' => '',

	// Optional. Read-only key for reconciling payments from the Zeffy API.
	'zeffy_api_key' => '',
);
