<?php
/**
 * Shared test harness: a throwaway database and a tiny assertion helper.
 */

declare(strict_types=1);

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function fresh_database(): string {
	$path = sys_get_temp_dir() . '/pause-cafe-test-' . bin2hex( random_bytes( 6 ) ) . '.sqlite';

	foreach ( array( '', '-wal', '-shm' ) as $suffix ) {
		if ( is_file( $path . $suffix ) ) {
			unlink( $path . $suffix );
		}
	}

	$GLOBALS['PAUSE_CAFE_DB_OVERRIDE'] = $path;

	return $path;
}

function check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++$GLOBALS['pass'];
		echo "  ok    $label\n";

		return;
	}

	++$GLOBALS['fail'];
	echo "  FAIL  $label\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

function check_throws( string $label, callable $callback, string $needle = '' ): void {
	try {
		$callback();
	} catch ( \Throwable $e ) {
		if ( '' === $needle || false !== stripos( $e->getMessage(), $needle ) ) {
			++$GLOBALS['pass'];
			echo "  ok    $label\n";

			return;
		}

		++$GLOBALS['fail'];
		echo "  FAIL  $label\n";
		echo '        threw the wrong thing: ' . $e->getMessage() . "\n";

		return;
	}

	++$GLOBALS['fail'];
	echo "  FAIL  $label\n";
	echo "        expected it to be refused, but it went through\n";
}

function finish(): void {
	echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";

	exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
}
