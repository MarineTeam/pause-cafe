<?php
/**
 * Looks: the design tokens, the theme stack, and uploaded pictures.
 *
 * The theme tests build a throwaway theme in the system temp directory rather
 * than leaning on the one that ships, so they still mean something if that
 * theme is changed or removed.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-design.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Design;
use PauseCafe\Images;
use PauseCafe\Settings;
use PauseCafe\Themes;
use PauseCafe\View;

/* =========================================================================
 * Tokens
 * ====================================================================== */

echo "An untouched site adds no CSS of its own\n";

check( 'nothing is emitted', Design::css(), '' );

echo "\nAnd the defaults are the ones in the stylesheet\n";

/*
 * The coupling that would otherwise rot silently. Design::css() suppresses any
 * token still at its default, so a default that disagrees with app.css means
 * the page shows the stylesheet's value and the organiser's screen shows a
 * different one, with nothing to explain the difference.
 */
$stylesheet = (string) file_get_contents( dirname( __DIR__ ) . '/public/assets/app.css' );
$rootBlock  = '';

if ( preg_match( '/:root \{(.+?)\n\}/s', $stylesheet, $found ) ) {
	$rootBlock = $found[1];
}

check( 'the stylesheet has a :root block', '' !== $rootBlock, true );

foreach ( Design::tokens() as $key => $token ) {
	if ( '' === $token['css'] || Design::TYPE_SELECT === $token['type'] ) {
		continue;
	}

	$expected = Design::TYPE_RANGE === $token['type']
		? $token['default'] . ( $token['suffix'] ?? '' )
		: $token['default'];

	check(
		$token['css'] . ' matches the stylesheet',
		(bool) preg_match( '/' . preg_quote( $token['css'], '/' ) . ':\s*' . preg_quote( (string) $expected, '/' ) . ';/', $rootBlock ),
		true
	);
}

echo "\nChanging one thing emits one line\n";

Design::set( 'design_button', '#aa3311' );

$css = Design::css();

check( 'the changed token is there', str_contains( $css, '--button: #aa3311;' ), true );
check( 'and nothing else is', substr_count( $css, ';' ), 1 );

Design::set( 'design_button', '#0f6e56' );

check( 'setting it back empties the block again', Design::css(), '' );

echo "\nA colour box cannot be used to write CSS\n";

// The value lands inside a <style> block, where a stray brace would let the
// rest of it become arbitrary rules.
check( 'a value with a brace in it is refused', Design::set( 'design_ink', '#fff; } body { display:none' ), false );
check( 'a word is refused', Design::set( 'design_ink', 'red' ), false );
check( 'a javascript url is refused', Design::set( 'design_ink', 'url(javascript:alert(1))' ), false );
check( 'nothing was stored', Design::css(), '' );
check( 'but a real colour is kept', Design::set( 'design_ink', '#123456' ), true );
check( 'and emitted', str_contains( Design::css(), '--ink: #123456;' ), true );

Design::set( 'design_ink', '#111314' );

echo "\nNumbers are clamped rather than believed\n";

Design::set( 'design_radius', '999' );
check( 'an absurd radius is pulled back to the maximum', str_contains( Design::css(), '--radius: 20px;' ), true );

check( 'a negative one is refused outright', Design::set( 'design_radius', '-5' ), false );
check( 'and so is a word', Design::set( 'design_radius', 'lots' ), false );

Design::set( 'design_radius', '14' );

echo "\nSelects only accept what they offer\n";

check( 'an unknown font is refused', Design::set( 'design_font_heading', 'comic-sans' ), false );
check( 'a known one is kept', Design::set( 'design_font_heading', 'serif' ), true );
check( 'and becomes a real stack', str_contains( Design::css(), 'Georgia' ), true );

Design::set( 'design_font_heading', 'sans' );

echo "\nThe site name is not a way into the page\n";

Design::set( 'design_brand_name', "Lunch\r\nBcc: someone@example.org" );

check(
	'newlines are stripped, since this reaches an email header',
	Design::brandName(),
	'Lunch Bcc: someone@example.org'
);

check( 'and it is capped', mb_strlen( Design::brandName() ) <= 80, true );

Design::set( 'design_brand_name', 'Pause Cafe' );

echo "\nPresets and reset\n";

check( 'applying an unknown preset does nothing', Design::applyPreset( 'nonsense' ), false );
check( 'applying a real one works', Design::applyPreset( 'current' ), true );
check( 'and it changed the look', str_contains( Design::css(), '--radius: 0px;' ), true );

Design::reset();

check( 'reset puts everything back', Design::css(), '' );

echo "\nDark mode is a setting, not a guess\n";

check( 'auto says nothing and lets the device decide', Design::themeAttribute(), '' );

Design::set( 'design_mode', 'on' );
check( 'always-dark stamps the attribute', Design::themeAttribute(), 'dark' );

Design::set( 'design_mode', 'off' );
check( 'always-light stamps the other one', Design::themeAttribute(), 'light' );

Design::set( 'design_mode', 'auto' );

/* =========================================================================
 * Themes
 * ====================================================================== */

echo "\nA theme replaces the templates it provides and no others\n";

$themeRoot = sys_get_temp_dir() . '/pause-cafe-themes-' . bin2hex( random_bytes( 4 ) );

mkdir( $themeRoot . '/tester/views/partials', 0777, true );

file_put_contents(
	$themeRoot . '/tester/theme.php',
	"<?php return array( 'name' => 'Tester', 'description' => 'For the tests.' );"
);

file_put_contents( $themeRoot . '/tester/style.css', '.dish { color: red; }' );
file_put_contents( $themeRoot . '/tester/views/partials/dish-card.php', 'OVERRIDDEN' );

// A directory with no manifest is not a theme.
mkdir( $themeRoot . '/not-a-theme', 0777, true );

Themes::configure( $themeRoot );

check( 'only the one with a manifest is found', array_keys( Themes::all() ), array( 'tester' ) );
check( 'it reads its name', Themes::all()['tester']['name'], 'Tester' );

echo "\nNothing is active until it is chosen\n";

check( 'no theme by default', Themes::slug(), '' );
check( 'and no stylesheet', Themes::stylesheetUrl(), '' );

Settings::set( 'design_theme', 'tester' );

check( 'choosing one activates it', Themes::slug(), 'tester' );
check( 'its stylesheet is offered', str_starts_with( Themes::stylesheetUrl(), '/theme.css?v=' ), true );

echo "\nThe stored slug is never trusted as a path\n";

foreach ( array( '../../etc', '..', 'tester/../..', '/etc/passwd', 'Tester', 'tes ter' ) as $attempt ) {
	Settings::set( 'design_theme', $attempt );

	check( 'refuses ' . var_export( $attempt, true ), Themes::slug(), '' );
	check( 'and finds no templates for it', Themes::viewPath(), '' );
}

// A theme that is deleted while selected must not take the site down with it.
Settings::set( 'design_theme', 'was-uninstalled' );

check( 'a theme that no longer exists falls back to core', Themes::slug(), '' );
check( 'and to the core templates', Themes::path(), '' );

echo "\nTemplates fall through to core\n";

Settings::set( 'design_theme', 'tester' );

View::configure( dirname( __DIR__ ) . '/views', Themes::viewPath() );

check(
	'the overridden one comes from the theme',
	str_contains( View::locate( 'partials/dish-card' ), 'tester' ),
	true
);

check(
	'one it does not provide comes from core',
	str_contains( View::locate( 'partials/order-fields' ), 'tester' ),
	false
);

check( 'and is really there', is_file( View::locate( 'partials/order-fields' ) ), true );
check( 'a template nobody has is missing', View::locate( 'no/such/thing' ), '' );

// Back to the built-in views for anything that follows.
Settings::set( 'design_theme', '' );
View::configure( dirname( __DIR__ ) . '/views' );

check( 'with no theme, core answers again', str_contains( View::locate( 'partials/dish-card' ), 'tester' ), false );

/* =========================================================================
 * Pictures
 * ====================================================================== */

echo "\nAn upload has to actually be a picture\n";

$uploads = sys_get_temp_dir() . '/pause-cafe-uploads-' . bin2hex( random_bytes( 4 ) );

Images::configure( $uploads, '/assets/uploads' );

/*
 * accept() insists on is_uploaded_file(), which cannot be true outside a real
 * request, so the checks that run before it are what these exercise: the type
 * sniffing and the size cap. The happy path is covered over HTTP in e2e.sh.
 */
$scratch = sys_get_temp_dir() . '/pause-cafe-img-' . bin2hex( random_bytes( 4 ) );

mkdir( $scratch, 0777, true );

$php = $scratch . '/evil.jpg';
file_put_contents( $php, '<?php echo "pwned";' );

check_throws(
	'a script wearing a .jpg is refused',
	static fn() => Images::accept( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $php ) ),
	'could not be read'
);

check_throws(
	'so is a missing file',
	static fn() => Images::accept( array( 'error' => UPLOAD_ERR_NO_FILE ) ),
	'No picture'
);

check_throws(
	'and one the server rejected for size',
	static fn() => Images::accept( array( 'error' => UPLOAD_ERR_INI_SIZE ) ),
	'too large'
);

check_throws(
	'and a path that was never uploaded',
	static fn() => Images::accept( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/etc/passwd' ) ),
	'could not be read'
);

echo "\nDeleting a picture only ever touches the uploads folder\n";

mkdir( $uploads, 0777, true );

$kept = $uploads . '/' . str_repeat( 'a', 24 ) . '.png';
file_put_contents( $kept, 'x' );

$bystander = $scratch . '/important.png';
file_put_contents( $bystander, 'x' );

Images::forget( '/assets/uploads/../../../../' . basename( $bystander ) );
check( 'a traversing name is ignored', is_file( $bystander ), true );

Images::forget( '/assets/uploads/anything-else.png' );
check( 'a name that is not one of ours is ignored', is_file( $kept ), true );

Images::forget( '/assets/uploads/' . basename( $kept ) );
check( 'and one of ours is removed', is_file( $kept ), false );

// Tidy up.
foreach ( array( $scratch, $uploads ) as $dir ) {
	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		unlink( (string) $file );
	}

	if ( is_dir( $dir ) ) {
		rmdir( $dir );
	}
}

// Files first, then directories deepest-first, or rmdir refuses.
foreach ( array( '/tester/views/partials/dish-card.php', '/tester/style.css', '/tester/theme.php' ) as $leftover ) {
	if ( is_file( $themeRoot . $leftover ) ) {
		unlink( $themeRoot . $leftover );
	}
}

foreach ( array( '/tester/views/partials', '/tester/views', '/tester', '/not-a-theme', '' ) as $leftover ) {
	if ( is_dir( $themeRoot . $leftover ) ) {
		rmdir( $themeRoot . $leftover );
	}
}

finish();
