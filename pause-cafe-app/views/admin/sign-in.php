<?php
/**
 * Which ways of signing in are switched on, and their settings.
 *
 * Built from the register, so a newly written method appears here with its own
 * fields without this file changing.
 */

use PauseCafe\Csrf;
use PauseCafe\Settings;
use PauseCafe\SignIn;
use PauseCafe\SignIn\Method;
use PauseCafe\SignIn\OidcMethod;

$routes = SignIn::organiserRoutes();
?>

<h1>Signing in</h1>

<p class="muted">
	How members prove who they are. Several can be on at once — a congregation
	moving to a provider can leave passwords on for whoever has not moved yet.
</p>

<?php if ( ! $routes ) : ?>
	<div class="flash flash--error">
		Nothing usable is switched on. The password sign-in is being kept available
		so this site does not lock you out.
	</div>
<?php endif; ?>

<?php
/*
 * Emailed links carry a one-time token in a URL the server builds. Without a
 * pinned address that URL comes from the request's Host header, so somebody
 * can ask for a link while claiming to be a different host and have the token
 * emailed pointing at theirs. Only worth saying when links are actually on.
 */
?>
<?php if ( SignIn::isAvailable( 'magic' ) && ! \PauseCafe\Notifications::urlIsPinned() ) : ?>
	<div class="flash flash--error">
		<strong>Set <code>site_url</code> in <code>config.php</code>.</strong>
		Sign-in links are switched on, and without it the address in those emails
		is taken from whatever the browser asked for — which means a link carrying
		a working sign-in token can be made to point somewhere else.
	</div>
<?php endif; ?>

<form method="post" action="/admin/signin">
	<?= Csrf::field() ?>

	<?php foreach ( $methods as $id => $method ) : ?>
		<?php $enabled = SignIn::isEnabled( $id ); ?>

		<div class="panel">
			<h3>
				<label style="display:inline; font-weight:600">
					<input type="checkbox" name="enabled[<?= e( $id ) ?>]" value="1" <?= $enabled ? 'checked' : '' ?>>
					<?= e( $method->label() ) ?>
				</label>

				<?php if ( $enabled && ! $method->isConfigured() ) : ?>
					<span class="pill pill--closed">Not set up</span>
				<?php elseif ( $enabled ) : ?>
					<span class="pill pill--open">On</span>
				<?php endif; ?>
			</h3>

			<p class="muted"><?= e( $method->describe() ) ?></p>

			<?php if ( $enabled && ! $method->isConfigured() ) : ?>
				<p class="help"><?= e( $method->requirement() ) ?></p>
			<?php endif; ?>

			<?php if ( $method instanceof OidcMethod ) : ?>
				<p class="help">
					Callback URL, which has to be allowed in your <?= e( $method->label() ) ?> application:<br>
					<code><?= e( $method->redirectUri() ) ?></code>
				</p>
			<?php endif; ?>

			<?php if ( $method->fields() ) : ?>
				<div class="field-row">
					<?php foreach ( $method->fields() as $key => $field ) : ?>
						<div>
							<label for="<?= e( $key ) ?>"><?= e( $field['label'] ) ?></label>

							<?php if ( 'password' === $field['type'] ) : ?>
								<input type="password" id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
									autocomplete="new-password"
									placeholder="<?= '' !== Settings::get( $key ) ? 'Set — leave blank to keep' : 'Not set' ?>">
							<?php else : ?>
								<input type="text" id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
									value="<?= e( Settings::get( $key ) ) ?>"
									placeholder="<?= e( $field['placeholder'] ?? '' ) ?>">
							<?php endif; ?>

							<?php if ( ! empty( $field['help'] ) ) : ?>
								<p class="help"><?= e( $field['help'] ) ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<div class="panel">
		<h3>Safety net</h3>

		<?php $mayDisable = SignIn::rescueMayBeDisabled(); ?>

		<div class="field">
			<label>
				<input type="checkbox" name="signin_admin_rescue" value="1"
					<?= SignIn::rescueAllowed() ? 'checked' : '' ?>
					<?= $mayDisable ? '' : 'disabled' ?>>
				Organisers can always sign in with a password
				<?php if ( ! $mayDisable ) : ?>
					<span class="pill pill--open">Held on</span>
				<?php endif; ?>
			</label>

			<?php if ( $mayDisable ) : ?>
				<p class="help">
					Keeps a way in that does not depend on anything outside this server.
					Turning it off is allowed now that a provider has been shown to work,
					but it does mean an expired provider account would lock every
					organiser out.
				</p>
			<?php else : ?>
				<p class="help"><?= e( SignIn::rescueLockReason() ) ?></p>

				<?php // A disabled checkbox posts nothing, which would read as "turn it off". ?>
				<input type="hidden" name="signin_admin_rescue" value="1">
			<?php endif; ?>
		</div>

		<div class="field">
			<label>
				<input type="checkbox" name="signin_external_create" value="1"
					<?= 'no' !== Settings::get( 'signin_external_create', 'yes' ) ? 'checked' : '' ?>>
				Make an account the first time somebody signs in with a provider
			</label>
			<p class="help">
				They still land unapproved and still cannot order until an organiser
				lets them in. Turn this off to accept only people whose email address
				already has an account here.
			</p>
		</div>

		<p class="help">
			Ways an organiser can currently get in:
			<strong><?= $routes ? e( implode( ', ', $routes ) ) : 'none' ?></strong>.
			<?php if ( ! $mayDisable ) : ?>
				Of those, only the password has actually been used — the rest are
				configured, which is not the same thing.
			<?php endif; ?>
		</p>
	</div>

	<button type="submit">Save sign-in settings</button>
</form>

<?php if ( $pending ) : ?>
	<div class="panel">
		<h3>Waiting to be joined up</h3>

		<p class="muted">
			Somebody signed in with an outside provider using an address that already
			belongs to an account here. A confirmed address shows they can read that
			mailbox now — not that they are the person who opened the account, which is
			why these are not joined up on their own.
		</p>

		<p class="muted">
			<strong>Approve only if you know it is the same person.</strong> Approving
			hands them that account and everything in it, including any balance.
			Approving does not sign them in: they go back to the login page and come
			through the provider again.
		</p>

		<div class="table-scroll">
			<table>
				<thead>
					<tr>
						<th>Signed in as</th><th>Provider</th><th>Wants the account of</th>
						<th>Since</th><th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pending as $request ) : ?>
						<tr>
							<td>
								<strong><?= e( '' !== $request['name'] ? $request['name'] : 'No name given' ) ?></strong><br>
								<span class="muted"><?= e( $request['email'] ) ?></span>
							</td>
							<td><?= e( SignIn::label( (string) $request['provider'] ) ) ?></td>
							<td>
								<strong><?= e( $request['user_name'] ) ?></strong><br>
								<span class="muted"><?= e( $request['user_email'] ) ?></span>
								<?php if ( \PauseCafe\Users::ROLE_ADMIN === (string) $request['role'] ) : ?>
									<br><span class="pill pill--closed">Organiser account</span>
								<?php endif; ?>
							</td>
							<td class="muted"><?= e( $request['created_at'] ) ?></td>
							<td>
								<?php // Two buttons, one form: only the pressed one submits its value. ?>
								<form method="post" action="/admin/signin/link">
									<?= Csrf::field() ?>
									<input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
									<button type="submit" name="decision" value="approve"
										onclick="return confirm('Hand <?= e( $request['user_name'] ) ?>\'s account, and anything in it, to whoever signed in as <?= e( $request['email'] ) ?>?')">
										Approve
									</button>
									<button type="submit" class="button button--quiet" name="decision" value="decline">Decline</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>

<div class="panel">
	<h3>Linked accounts</h3>

	<?php if ( ! $links ) : ?>
		<p class="muted">Nobody has signed in with an outside provider yet.</p>
	<?php else : ?>
		<p class="muted">
			Which external account each person signs in with. Unlinking does not delete
			anybody — they simply have to link again next time, or use another method.
		</p>

		<table>
			<thead>
				<tr><th>Person</th><th>Provider</th><th>Address there</th><th>Last used</th><th></th></tr>
			</thead>
			<tbody>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td>
							<strong><?= e( $link['name'] ) ?></strong><br>
							<span class="muted"><?= e( $link['email'] ) ?></span>
						</td>
						<td><?= e( SignIn::label( (string) $link['provider'] ) ) ?></td>
						<td class="muted"><?= e( $link['identity_email'] ) ?></td>
						<td class="muted"><?= e( '' !== $link['last_seen_at'] ? $link['last_seen_at'] : 'never' ) ?></td>
						<td>
							<form method="post" action="/admin/signin/unlink"
								onsubmit="return confirm('Unlink this account?')">
								<?= Csrf::field() ?>
								<input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
								<button type="submit" class="button button--quiet">Unlink</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
