<?php use PauseCafe\Csrf; ?>

<h1>Kitchen list</h1>

<?php if ( ! $protected ) : ?>

	<p class="muted form-narrow">
		This page is for organisers only. An organiser can set a shared password in
		Settings to let the kitchen team in without an account.
	</p>

	<p><a class="button" href="/login">Sign in</a></p>

<?php else : ?>

	<p class="muted form-narrow">
		Enter the shared password to see what needs cooking. Organisers can just
		<a href="/login">sign in</a>.
	</p>

	<form method="post" action="/kitchen/unlock" class="form-narrow">
		<?= Csrf::field() ?>

		<div class="field">
			<label for="password">Password</label>
			<input type="password" id="password" name="password" required autofocus autocomplete="current-password">
		</div>

		<button type="submit">Show the list</button>
	</form>

<?php endif; ?>
