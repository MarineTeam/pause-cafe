<?php
use PauseCafe\Csrf;
use PauseCafe\Schedule;
use PauseCafe\Schedules;

include __DIR__ . '/_tabs.php';

$weekdays = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );

/**
 * The rule fields, shared by the edit forms and the create form.
 */
$fields = static function ( array $rules, array $locations, array $chosen ) use ( $weekdays ): void {
	?>
	<div class="field-row">
		<div>
			<label>Name</label>
			<input type="text" name="name" value="<?= e( $rules['name'] ) ?>" required>
		</div>
		<div>
			<label>When ordering opens</label>
			<select name="mode">
				<?php foreach ( Schedule::modes() as $value => $label ) : ?>
					<option value="<?= e( $value ) ?>" <?= $value === $rules['mode'] ? 'selected' : '' ?>>
						<?= e( $label ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label>Order on the list</label>
			<input type="number" name="sort_order" value="<?= (int) $rules['sort_order'] ?>">
		</div>
	</div>

	<h4>Planned ahead</h4>
	<div class="field-row">
		<div>
			<label>Food is served on</label>
			<select name="service_weekday">
				<?php foreach ( $weekdays as $index => $day ) : ?>
					<option value="<?= (int) $index ?>" <?= (int) $rules['service_weekday'] === $index ? 'selected' : '' ?>>
						<?= e( $day ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label>Opens, days before</label>
			<input type="number" name="open_days_before" min="0" max="30" value="<?= (int) $rules['open_days_before'] ?>">
		</div>
		<div>
			<label>At</label>
			<input type="time" name="open_time" value="<?= e( $rules['open_time'] ) ?>">
		</div>
		<div>
			<label>Closes, days before</label>
			<input type="number" name="close_days_before" min="0" max="30" value="<?= (int) $rules['close_days_before'] ?>">
		</div>
	</div>

	<h4>On publish</h4>
	<div class="field-row">
		<div>
			<label>Ordering closes on</label>
			<select name="close_weekday">
				<?php foreach ( $weekdays as $index => $day ) : ?>
					<option value="<?= (int) $index ?>" <?= (int) $rules['close_weekday'] === $index ? 'selected' : '' ?>>
						<?= e( $day ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label>Served, days after the cutoff</label>
			<input type="number" name="service_days_after_close" min="0" max="14"
				value="<?= (int) $rules['service_days_after_close'] ?>">
		</div>
	</div>

	<h4>Both</h4>
	<div class="field-row">
		<div>
			<label>Closing time</label>
			<input type="time" name="close_time" value="<?= e( $rules['close_time'] ) ?>">
		</div>
	</div>

	<div class="field">
		<label>
			<input type="checkbox" name="preview_upcoming" value="1" <?= $rules['preview_upcoming'] ? 'checked' : '' ?>>
			Show dishes before ordering opens
		</label>
	</div>

	<div class="field">
		<label>
			<input type="checkbox" name="show_on_front" value="1" <?= $rules['show_on_front'] ? 'checked' : '' ?>>
			Show this menu on the front page
		</label>
	</div>

	<div class="field">
		<label>Pickup locations</label>
		<p class="help">Tick none to use every location.</p>
		<?php foreach ( $locations as $location ) : ?>
			<label style="font-weight:400">
				<input type="checkbox" name="locations[]" value="<?= (int) $location['id'] ?>"
					<?= in_array( (int) $location['id'], $chosen, true ) ? 'checked' : '' ?>>
				<?= e( $location['name'] ) ?>
			</label>
		<?php endforeach; ?>
	</div>
	<?php
};
?>

<h1>Schedules</h1>

<p class="muted">
	A schedule decides when its dishes can be ordered. Most sites need one. Add
	more to run a second menu on its own rhythm — a Wednesday supper closing
	Tuesday evening alongside the Sunday lunch.
</p>

<div class="panel">
	<h2><?= e( $schedules[ Schedules::DEFAULT_ID ]['name'] ) ?> <span class="pill pill--past">Default</span></h2>
	<p class="muted">
		Every dish with no schedule of its own follows this one. Its rules live in
		<a href="/admin/settings">Settings</a>, alongside the rest of the site
		configuration, rather than being duplicated here.
	</p>
	<p>
		<?= e( Schedule::modes()[ $schedules[ Schedules::DEFAULT_ID ]['mode'] ] ?? '' ) ?><br>
		<span class="muted">
			<?= $schedules[ Schedules::DEFAULT_ID ]['show_on_front'] ? 'Shown on the front page.' : 'Hidden from the front page.' ?>
		</span>
	</p>
	<p>
		<a class="button button--quiet" href="/admin/settings">Edit in Settings</a>
		<a class="button button--quiet" href="/admin/menu/builder?schedule=0">Build its menu</a>
	</p>
</div>

<?php foreach ( $schedules as $scheduleId => $rules ) : ?>
	<?php if ( Schedules::DEFAULT_ID === $scheduleId ) : ?>
		<?php continue; ?>
	<?php endif; ?>

	<div class="panel">
		<h2><?= e( $rules['name'] ) ?></h2>
		<p class="muted">
			<?= e( Schedule::modes()[ $rules['mode'] ] ?? $rules['mode'] ) ?> ·
			<?= $rules['show_on_front'] ? 'on the front page' : 'not on the front page' ?>
		</p>

		<p>
			<a class="button button--quiet" href="/admin/menu/builder?schedule=<?= (int) $scheduleId ?>">Build its menu</a>
		</p>

		<details>
			<summary>Edit rules</summary>

			<form method="post" action="/admin/schedules/save" style="margin-top:14px">
				<?= Csrf::field() ?>
				<input type="hidden" name="id" value="<?= (int) $scheduleId ?>">
				<?php $fields( $rules, $locations, $assigned[ $scheduleId ] ?? array() ); ?>
				<button type="submit">Save schedule</button>
			</form>

			<form method="post" action="/admin/schedules/<?= (int) $scheduleId ?>/delete" style="margin-top:12px"
				onsubmit="return confirm('Remove this schedule? Its dishes fall back to the default.')">
				<?= Csrf::field() ?>
				<button type="submit" class="link-button">Remove schedule</button>
			</form>
		</details>
	</div>
<?php endforeach; ?>

<div class="panel">
	<h2>Add a schedule</h2>

	<form method="post" action="/admin/schedules/save">
		<?= Csrf::field() ?>
		<?php
		$blank         = Schedules::defaultRules();
		$blank['name'] = '';
		$fields( $blank, $locations, array() );
		?>
		<button type="submit">Create schedule</button>
	</form>
</div>
