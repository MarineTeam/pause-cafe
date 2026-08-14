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

include __DIR__ . '/_tabs.php';

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

		<div class="field">
			<label>
				<input type="checkbox" name="signin_admin_rescue" value="1"
					<?= SignIn::rescueAllowed() ? 'checked' : '' ?>>
				Organisers can always sign in with a password
			</label>
			<p class="help">
				Keeps a way in that does not depend on anything outside this server.
				Turning it off means a mistyped client secret or an expired provider
				account locks every organiser out, with no way back except editing
				the database by hand. Leave it on.
			</p>
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
		</p>
	</div>

	<button type="submit">Save sign-in settings</button>
</form>

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
