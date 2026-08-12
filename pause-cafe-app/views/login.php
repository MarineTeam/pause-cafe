<?php
use PauseCafe\Csrf;
use PauseCafe\Settings;
?>

<h1>Sign in</h1>

<form method="post" action="/login" class="form-narrow">
	<?= Csrf::field() ?>

	<div class="field">
		<label for="email">Email</label>
		<input type="email" id="email" name="email" required autofocus autocomplete="username">
	</div>

	<div class="field">
		<label for="password">Password</label>
		<input type="password" id="password" name="password" required autocomplete="current-password">
	</div>

	<button type="submit">Sign in</button>
</form>

<?php if ( Settings::bool( 'allow_registration' ) ) : ?>
	<p class="help">No account yet? <a href="/register">Create one</a> — an organiser will approve it.</p>
<?php else : ?>
	<p class="help">Accounts are created by the organisers. Please ask them.</p>
<?php endif; ?>
