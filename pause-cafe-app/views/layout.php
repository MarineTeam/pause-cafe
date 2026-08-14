<?php
use PauseCafe\Auth;
use PauseCafe\Cart;
use PauseCafe\Design;
use PauseCafe\Settings;
use PauseCafe\Themes;
use PauseCafe\View;

$user      = Auth::user();
$cartCount = Auth::check() ? Cart::count() : 0;
$flash     = View::takeFlash();

$brand     = Design::brandName();
$logo      = Design::logo();
$mode      = Design::themeAttribute();
$designCss = Design::css();
$themeCss  = Themes::stylesheetUrl();
?><!doctype html>
<html lang="en"<?= '' !== $mode ? ' data-theme="' . e( $mode ) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e( $title ? $title . ' — ' . $brand : $brand ) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= e( PAUSE_CAFE_VERSION ) ?>">
<?php if ( '' !== $themeCss ) : ?>
	<?php // After app.css, so a theme overrides rather than fights it. ?>
	<link rel="stylesheet" href="<?= e( $themeCss ) ?>">
<?php endif; ?>
<?php if ( '' !== $designCss ) : ?>
	<?php
	/*
	 * Last, and inline, so the organiser's choices beat both stylesheets and
	 * arrive in the first response rather than a second request. Only tokens
	 * that differ from their default are here, so this is usually a few lines
	 * and often nothing at all.
	 */
	?>
	<style><?= $designCss ?></style>
<?php endif; ?>
</head>
<body>

<header class="site-header">
	<div class="wrap header-inner">
		<a class="brand" href="/">
			<?php if ( '' !== $logo ) : ?>
				<img src="<?= e( $logo ) ?>" alt="<?= e( $brand ) ?>" class="brand__logo">
			<?php else : ?>
				<?= e( $brand ) ?>
			<?php endif; ?>
		</a>

		<nav class="site-nav">
			<a href="/">Menu</a>
			<?php if ( $user ) : ?>
				<a href="/account">Account</a>
				<?php if ( Auth::isAdmin() ) : ?>
					<a href="/admin">Organiser</a>
				<?php endif; ?>
				<a href="/cart" class="cart-link">Cart<?= $cartCount ? ' (' . (int) $cartCount . ')' : '' ?></a>
				<form method="post" action="/logout" class="inline">
					<?= \PauseCafe\Csrf::field() ?>
					<button type="submit" class="link-button">Sign out</button>
				</form>
			<?php else : ?>
				<a href="/login">Sign in</a>
			<?php endif; ?>
		</nav>
	</div>
</header>

<main class="wrap">
	<?php foreach ( $flash as $message ) : ?>
		<div class="flash flash--<?= e( $message['type'] ) ?>"><?= e( $message['message'] ) ?></div>
	<?php endforeach; ?>

	<?php if ( $user && 0 === (int) $user['is_approved'] ) : ?>
		<div class="flash flash--notice">
			Your account is waiting for an organiser to approve it. You can look at the menu, but not order yet.
		</div>
	<?php endif; ?>

	<?= $content ?>
</main>

<footer class="site-footer">
	<div class="wrap">
		<p><?= e( Settings::get( 'menu_note' ) ) ?></p>
	</div>
</footer>

</body>
</html>
