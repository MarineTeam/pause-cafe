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
	 * Zeffy sends a POST to /webhook/zeffy when a payment completes. Set a long
	 * random string here and configure the same one in Zeffy so the endpoint can
	 * tell a real callback from anyone who guesses the URL.
	 */
	'zeffy_secret' => '',

	// Optional. Read-only key for reconciling payments from the Zeffy API.
	'zeffy_api_key' => '',
);
