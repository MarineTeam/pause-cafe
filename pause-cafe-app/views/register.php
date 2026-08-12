<?php use PauseCafe\Csrf; ?>

<h1>Create an account</h1>

<p class="muted form-narrow">
	Once an organiser approves your account you can order and top up your wallet.
</p>

<form method="post" action="/register" class="form-narrow">
	<?= Csrf::field() ?>

	<div class="field">
		<label for="name">Your name</label>
		<input type="text" id="name" name="name" required autofocus>
	</div>

	<div class="field">
		<label for="group_name">Group</label>
		<input type="text" id="group_name" name="group_name" placeholder="e.g. Youth">
		<p class="help">Used as the default on your orders. You can change it per meal.</p>
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

	<button type="submit">Create account</button>
</form>
