<?php
/**
 * The sign-in page.
 *
 * Renders whatever SignIn::available() returned, in registration order. It has
 * no idea what any of them are — a method says which of the three prompts it
 * wants and this draws it.
 */

use PauseCafe\Csrf;
use PauseCafe\Settings;
use PauseCafe\SignIn;
use PauseCafe\SignIn\Method;

$passwords = array_filter( $methods, static fn( Method $m ) => Method::PROMPT_PASSWORD === $m->prompt() );
$emails    = array_filter( $methods, static fn( Method $m ) => Method::PROMPT_EMAIL === $m->prompt() );
$buttons   = array_filter( $methods, static fn( Method $m ) => Method::PROMPT_BUTTON === $m->prompt() );

$forms = count( $passwords ) + count( $emails );
$shown = 0;
?>

<h1>Sign in</h1>

<?php if ( $rescue ) : ?>

	<div class="flash flash--notice">
		Organiser sign-in. Members should use the <a href="/login">usual page</a>.
	</div>

	<form method="post" action="/login/rescue" class="form-narrow">
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

<?php else : ?>

	<?php foreach ( $passwords as $method ) : ?>
		<?php ++$shown; ?>
		<form method="post" action="/login" class="form-narrow">
			<?= Csrf::field() ?>
			<input type="hidden" name="method" value="<?= e( $method->id() ) ?>">

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
	<?php endforeach; ?>

	<?php foreach ( $emails as $method ) : ?>
		<?php ++$shown; ?>
		<?php if ( $shown > 1 ) : ?>
			<p class="muted signin-or">or</p>
		<?php endif; ?>

		<form method="post" action="/login" class="form-narrow">
			<?= Csrf::field() ?>
			<input type="hidden" name="method" value="<?= e( $method->id() ) ?>">

			<div class="field">
				<label for="magic-email"><?= e( $method->label() ) ?></label>
				<input type="email" id="magic-email" name="email" required
					<?= 1 === $shown ? 'autofocus' : '' ?> autocomplete="username"
					placeholder="you@example.org">
				<p class="help">We email you a link. No password to remember.</p>
			</div>

			<button type="submit">Email me a link</button>
		</form>
	<?php endforeach; ?>

	<?php if ( $buttons ) : ?>
		<?php if ( $forms > 0 ) : ?>
			<p class="muted signin-or">or</p>
		<?php endif; ?>

		<div class="signin-buttons">
			<?php foreach ( $buttons as $method ) : ?>
				<form method="post" action="/auth/<?= e( $method->id() ) ?>/start">
					<?= Csrf::field() ?>
					<button type="submit" class="button button--quiet">
						Continue with <?= e( $method->label() ) ?>
					</button>
				</form>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( Settings::bool( 'allow_registration' ) && isset( $methods['password'] ) ) : ?>
		<p class="help">No account yet? <a href="/register">Create one</a> — an organiser will approve it.</p>
	<?php elseif ( ! isset( $methods['password'] ) ) : ?>
		<p class="help">First time? Sign in above and an organiser will approve you.</p>
	<?php else : ?>
		<p class="help">Accounts are created by the organisers. Please ask them.</p>
	<?php endif; ?>

	<?php if ( SignIn::rescueOffered() ) : ?>
		<p class="help muted"><a href="/login?rescue=1">Organiser sign-in</a></p>
	<?php endif; ?>

<?php endif; ?>
