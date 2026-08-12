<?php
use PauseCafe\Csrf;
use PauseCafe\Schedule;

include __DIR__ . '/_tabs.php';

$weekdays = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
$mode     = $settings['active_mode'];
?>

<h1>Settings</h1>

<form method="post" action="/admin/settings">
	<?= Csrf::field() ?>

	<div class="panel">
		<h2>Ordering mode</h2>
		<p class="muted">One mode is in force at a time. It decides how every dish's window is worked out.</p>

		<?php foreach ( Schedule::modes() as $value => $label ) : ?>
			<div class="field">
				<label>
					<input type="radio" name="active_mode" value="<?= e( $value ) ?>" <?= $mode === $value ? 'checked' : '' ?>>
					<?= e( $label ) ?>
				</label>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="panel">
		<h3>Planned mode</h3>
		<p class="muted">Used when the mode above is "planned ahead".</p>

		<div class="field-row">
			<div>
				<label for="service_weekday">Food is served on</label>
				<select id="service_weekday" name="service_weekday">
					<?php foreach ( $weekdays as $index => $day ) : ?>
						<option value="<?= (int) $index ?>" <?= (int) $settings['service_weekday'] === $index ? 'selected' : '' ?>>
							<?= e( $day ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="open_days_before">Opens (days before)</label>
				<input type="number" id="open_days_before" name="open_days_before" min="0" max="30"
					value="<?= e( $settings['open_days_before'] ) ?>">
			</div>
			<div>
				<label for="open_time">At</label>
				<input type="time" id="open_time" name="open_time" value="<?= e( $settings['open_time'] ) ?>">
			</div>
			<div>
				<label for="close_days_before">Closes (days before)</label>
				<input type="number" id="close_days_before" name="close_days_before" min="0" max="30"
					value="<?= e( $settings['close_days_before'] ) ?>">
			</div>
		</div>
		<p class="help">Default: served Sunday, opens 5 days before at 12:00 (Tuesday), closes 1 day before (Saturday).</p>
	</div>

	<div class="panel">
		<h3>On-publish mode</h3>
		<p class="muted">Used when the mode above is "on publish".</p>

		<div class="field-row">
			<div>
				<label for="close_weekday">Ordering closes on</label>
				<select id="close_weekday" name="close_weekday">
					<?php foreach ( $weekdays as $index => $day ) : ?>
						<option value="<?= (int) $index ?>" <?= (int) $settings['close_weekday'] === $index ? 'selected' : '' ?>>
							<?= e( $day ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="service_days_after_close">Served (days after cutoff)</label>
				<input type="number" id="service_days_after_close" name="service_days_after_close" min="0" max="14"
					value="<?= e( $settings['service_days_after_close'] ) ?>">
			</div>
		</div>
		<p class="help">A menu published at or after the cutoff runs to the following week rather than closing in the past.</p>
	</div>

	<div class="panel">
		<h3>All modes</h3>

		<div class="field-row">
			<div>
				<label for="close_time">Closing time</label>
				<input type="time" id="close_time" name="close_time" value="<?= e( $settings['close_time'] ) ?>">
			</div>
			<div>
				<label for="default_price">Default dish price</label>
				<input type="text" id="default_price" name="default_price" value="<?= e( $settings['default_price'] ) ?>">
			</div>
		</div>

		<div class="field">
			<label><input type="checkbox" name="preview_upcoming" value="1"
				<?= 'yes' === $settings['preview_upcoming'] ? 'checked' : '' ?>> Show dishes before ordering opens</label>
		</div>

		<div class="field">
			<label><input type="checkbox" name="allow_registration" value="1"
				<?= 'yes' === $settings['allow_registration'] ? 'checked' : '' ?>> Let people sign up themselves (still needs approval)</label>
		</div>

		<div class="field">
			<label><input type="checkbox" name="allow_negative_balance" value="1"
				<?= 'yes' === $settings['allow_negative_balance'] ? 'checked' : '' ?>> Let members order past their balance</label>
		</div>
	</div>

	<div class="panel">
		<h3>Payment methods</h3>
		<p class="muted">
			What people can choose at checkout. At least one has to stay on. If only
			one is enabled it is used silently, with no choice shown.
		</p>

		<?php foreach ( $methods as $methodId => $method ) : ?>
			<div class="field">
				<label>
					<input type="checkbox" name="payment[<?= e( $methodId ) ?>]" value="1"
						<?= \PauseCafe\Payments::isEnabled( $methodId ) ? 'checked' : '' ?>>
					<?= e( $method->label() ) ?>
				</label>
				<p class="help" style="margin-left:24px">
					<?= e( $method->description() ) ?>
					<?php if ( ! $method->settlesImmediately() ) : ?>
						Orders stay owing until an organiser marks them paid.
					<?php endif; ?>
				</p>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="panel">
		<h3>Storefront wording</h3>

		<div class="field">
			<label for="menu_heading">Menu heading</label>
			<input type="text" id="menu_heading" name="menu_heading" value="<?= e( $settings['menu_heading'] ) ?>">
		</div>

		<div class="field">
			<label for="menu_note">Footer note</label>
			<input type="text" id="menu_note" name="menu_note" value="<?= e( $settings['menu_note'] ) ?>">
		</div>
	</div>

	<button type="submit">Save settings</button>
</form>

<div class="panel">
	<h2>Pickup locations</h2>

	<table>
		<tbody>
			<?php foreach ( $locations as $location ) : ?>
				<tr>
					<td><?= e( $location['name'] ) ?></td>
					<td class="right">
						<form method="post" action="/admin/locations/<?= (int) $location['id'] ?>/delete"
							onsubmit="return confirm('Remove this location? Its dishes go too.')">
							<?= Csrf::field() ?>
							<button type="submit" class="link-button">Remove</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<form method="post" action="/admin/locations/add" class="field-row" style="max-width:420px">
		<?= Csrf::field() ?>
		<input type="text" name="name" placeholder="e.g. Marine" required>
		<button type="submit" class="button--quiet">Add location</button>
	</form>
</div>

<div class="panel">
	<h2>Email</h2>
	<p class="muted">
		Used for order confirmations, approval notices and a heads-up when someone
		signs up. If the chosen way of sending fails, the message is retried
		through PHP's mail() so it is not silently dropped.
	</p>

	<form method="post" action="/admin/mail">
		<?= Csrf::field() ?>

		<div class="field">
			<label>
				<input type="checkbox" name="mail_enabled" value="1"
					<?= 'no' !== $settings['mail_enabled'] ? 'checked' : '' ?>>
				Send email
			</label>
		</div>

		<div class="field-row">
			<div>
				<label for="mail_from_name">From name</label>
				<input type="text" id="mail_from_name" name="mail_from_name"
					value="<?= e( $settings['mail_from_name'] ) ?>" placeholder="Pause Cafe">
			</div>
			<div>
				<label for="mail_from_email">From address</label>
				<input type="email" id="mail_from_email" name="mail_from_email"
					value="<?= e( $settings['mail_from_email'] ) ?>" placeholder="lunch@example.org">
				<p class="help">Has to be an address the sending service is allowed to use.</p>
			</div>
		</div>

		<h3>How to send</h3>

		<?php foreach ( $mailers as $mailerId => $mailer ) : ?>
			<div class="field">
				<label>
					<input type="radio" name="mail_transport" value="<?= e( $mailerId ) ?>"
						<?= $mailerId === $settings['mail_transport'] ? 'checked' : '' ?>>
					<?= e( $mailer->label() ) ?>
					<?php if ( ! $mailer->isConfigured() ) : ?>
						<span class="pill pill--closed">Not set up</span>
					<?php endif; ?>
				</label>
				<p class="help" style="margin-left:24px"><?= e( $mailer->description() ) ?></p>

				<?php if ( $mailer->configFields() ) : ?>
					<div class="field-row" style="margin-left:24px">
						<?php foreach ( $mailer->configFields() as $key => $field ) : ?>
							<div>
								<label for="<?= e( $key ) ?>"><?= e( $field['label'] ) ?></label>

								<?php if ( 'select' === $field['type'] ) : ?>
									<select id="<?= e( $key ) ?>" name="<?= e( $key ) ?>">
										<?php foreach ( $field['options'] as $value => $optionLabel ) : ?>
											<option value="<?= e( $value ) ?>"
												<?= $value === \PauseCafe\Settings::get( $key ) ? 'selected' : '' ?>>
												<?= e( $optionLabel ) ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'password' === $field['type'] ) : ?>
									<input type="password" id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
										autocomplete="new-password"
										placeholder="<?= '' !== \PauseCafe\Settings::get( $key ) ? 'Set — leave blank to keep' : 'Not set' ?>">
								<?php else : ?>
									<input type="<?= 'number' === $field['type'] ? 'number' : 'text' ?>"
										id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
										value="<?= e( \PauseCafe\Settings::get( $key ) ) ?>">
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

		<button type="submit">Save email settings</button>
	</form>

	<form method="post" action="/admin/mail/test" style="margin-top:16px">
		<?= Csrf::field() ?>
		<button type="submit" class="button--quiet">Send a test to myself</button>
		<p class="help">Save first — the test uses the saved settings.</p>
	</form>
</div>

<div class="panel">
	<h2>Kitchen list access</h2>
	<p class="muted">
		The kitchen list lives at <code>/kitchen</code>. Organisers always see it.
		A shared password lets cooks and servers open it on a phone without an
		account — leave it unset and the page stays organisers only.
	</p>
	<p class="muted">
		It shows member names and what they ordered, so treat the password the way
		you would a door key.
	</p>

	<p>
		Currently:
		<?php if ( $kitchenOn ) : ?>
			<span class="pill pill--open">Shared password set</span>
		<?php else : ?>
			<span class="pill pill--past">Organisers only</span>
		<?php endif; ?>
	</p>

	<form method="post" action="/admin/kitchen-password" class="field-row" style="max-width:560px">
		<?= Csrf::field() ?>
		<input type="password" name="password" placeholder="New shared password" autocomplete="new-password">
		<button type="submit" class="button--quiet">Set password</button>
		<?php if ( $kitchenOn ) : ?>
			<button type="submit" name="clear" value="1" class="link-button" style="align-self:center">Clear it</button>
		<?php endif; ?>
	</form>
</div>

<div class="panel">
	<h2>Groups</h2>
	<p class="muted">
		What people can pick when ordering. Keeping it to a list rather than a text
		box is what stops the cook list filling up with "Youth", "youth" and "Yth"
		as three separate rows.
	</p>

	<?php if ( ! $groups ) : ?>
		<p class="muted">
			None yet — until you add one, the group field does not appear anywhere.
		</p>
	<?php else : ?>
		<table>
			<tbody>
				<?php foreach ( $groups as $group ) : ?>
					<tr>
						<td>
							<form method="post" action="/admin/groups/<?= (int) $group['id'] ?>/rename" class="field-row" style="max-width:420px">
								<?= Csrf::field() ?>
								<input type="text" name="name" value="<?= e( $group['name'] ) ?>" required>
								<button type="submit" class="button--quiet">Rename</button>
							</form>
						</td>
						<td class="right">
							<form method="post" action="/admin/groups/<?= (int) $group['id'] ?>/delete"
								onsubmit="return confirm('Remove this group from the list? People already in it keep it until you change them.')">
								<?= Csrf::field() ?>
								<button type="submit" class="link-button">Remove</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="help">Renaming moves everyone in that group across. Past orders keep the name they were placed under.</p>
	<?php endif; ?>

	<form method="post" action="/admin/groups/add" class="field-row" style="max-width:420px">
		<?= Csrf::field() ?>
		<input type="text" name="name" placeholder="e.g. Youth" required>
		<button type="submit" class="button--quiet">Add group</button>
	</form>

	<?php if ( $orphaned ) : ?>
		<div class="flash flash--notice" style="margin-top:16px">
			These groups are still on people's accounts but are not on the list:
			<strong><?= e( implode( ', ', $orphaned ) ) ?></strong>.
			Add them back, or edit those accounts on the People screen.
		</div>
	<?php endif; ?>
</div>

<div class="panel">
	<h2>Blackout dates</h2>
	<p class="muted">Days with no lunch. Dishes serving that day are hidden and the label is shown instead.</p>

	<?php if ( $blackouts ) : ?>
		<table>
			<tbody>
				<?php foreach ( $blackouts as $date => $label ) : ?>
					<tr>
						<td><?= e( Schedule::formatDate( $date, 'j F Y' ) ) ?></td>
						<td><?= e( $label ) ?></td>
						<td class="right">
							<form method="post" action="/admin/blackouts/remove">
								<?= Csrf::field() ?>
								<input type="hidden" name="service_date" value="<?= e( $date ) ?>">
								<button type="submit" class="link-button">Remove</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<form method="post" action="/admin/blackouts/add" class="field-row" style="max-width:560px">
		<?= Csrf::field() ?>
		<input type="date" name="service_date" required>
		<input type="text" name="label" placeholder="Christmas — no lunch">
		<button type="submit" class="button--quiet">Add blackout</button>
	</form>
</div>

<div class="panel">
	<h2>Zeffy</h2>

	<?php if ( $zeffyOn ) : ?>
		<p>
			Webhook endpoint: <code><?= e( ( $_SERVER['REQUEST_SCHEME'] ?? 'https' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? 'your-site' ) ) ?>/webhook/zeffy</code>
		</p>
		<p class="muted">
			Configure that URL in Zeffy and send the shared secret as the
			<code>X-Zeffy-Secret</code> header, or append <code>?key=…</code> to the URL.
			Payments are matched to accounts by email address.
		</p>

		<form method="post" action="/admin/zeffy/reconcile">
			<?= Csrf::field() ?>
			<button type="submit" class="button--quiet">Pull payments from Zeffy now</button>
		</form>
	<?php else : ?>
		<p class="muted">
			No Zeffy secret is set in <code>config.php</code>, so the webhook is turned
			off. Top up wallets by hand from the People screen until it is configured.
		</p>
	<?php endif; ?>
</div>
