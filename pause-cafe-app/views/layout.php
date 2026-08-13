<?php
use PauseCafe\Auth;
use PauseCafe\Cart;
use PauseCafe\Settings;
use PauseCafe\View;

$user      = Auth::user();
$cartCount = Auth::check() ? Cart::count() : 0;
$flash     = View::takeFlash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e( $title ? $title . ' — Pause Cafe' : 'Pause Cafe' ) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= e( PAUSE_CAFE_VERSION ) ?>">
</head>
<body>

<header class="site-header">
	<div class="wrap header-inner">
		<a class="brand" href="/">Pause Cafe</a>

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
