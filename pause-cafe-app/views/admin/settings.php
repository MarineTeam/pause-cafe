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
