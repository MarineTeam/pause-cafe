<?php use PauseCafe\Csrf; ?>

<h1>Set up Pause Cafe</h1>

<p class="muted form-narrow">
	There are no accounts yet. This creates the first organiser, who can then
	approve everyone else.
</p>

<form method="post" action="/setup" class="form-narrow">
	<?= Csrf::field() ?>

	<div class="field">
		<label for="name">Your name</label>
		<input type="text" id="name" name="name" required autofocus>
	</div>

	<div class="field">
		<label for="email">Email</label>
		<input type="email" id="email" name="email" required autocomplete="username">
	</div>

	<div class="field">
		<label for="password">Password</label>
		<input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
		<p class="help">At least 8 characters.</p>
	</div>

	<button type="submit">Create organiser account</button>
</form>
