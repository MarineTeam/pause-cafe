<?php
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;

include __DIR__ . '/_tabs.php';

$value = static function ( string $key, $fallback = '' ) use ( $item ) {
	return $item[ $key ] ?? $fallback;
};

$toInput = static function ( string $stored ): string {
	$parsed = Schedule::parseDateTime( $stored );

	return $parsed ? $parsed->format( 'Y-m-d\TH:i' ) : '';
};
?>

<h1><?= $item ? 'Edit dish' : 'Add a dish' ?></h1>

<?php if ( $item ) : ?>
	<p class="muted"><?= e( $item['window']->message() ) ?></p>
<?php endif; ?>

<form method="post" action="/admin/menu/save">
	<?= Csrf::field() ?>
	<?php if ( $item ) : ?>
		<input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
	<?php endif; ?>

	<div class="panel">
		<div class="field-row">
			<div>
				<label for="name">Dish</label>
				<input type="text" id="name" name="name" value="<?= e( $value( 'name' ) ) ?>" required autofocus>
			</div>
			<div>
				<label for="location_id">Pickup location</label>
				<select id="location_id" name="location_id" required>
					<?php foreach ( $locations as $location ) : ?>
						<option value="<?= (int) $location['id'] ?>"
							<?= (int) $value( 'location_id' ) === (int) $location['id'] ? 'selected' : '' ?>>
							<?= e( $location['name'] ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="price">Price</label>
				<input type="text" id="price" name="price"
					value="<?= e( $item ? Money::format( (int) $item['price_cents'] ) : \PauseCafe\Settings::get( 'default_price' ) ) ?>">
			</div>
			<div>
				<label for="capacity">Portions</label>
				<input type="number" id="capacity" name="capacity" min="0" value="<?= (int) $value( 'capacity', 0 ) ?>">
				<p class="help">0 means unlimited.</p>
			</div>
		</div>

		<div class="field">
			<label for="description">Description</label>
			<textarea id="description" name="description" rows="2"><?= e( $value( 'description' ) ) ?></textarea>
		</div>

		<div class="field">
			<label for="status">Status</label>
			<select id="status" name="status">
				<option value="published" <?= 'draft' !== $value( 'status', 'published' ) ? 'selected' : '' ?>>Published</option>
				<option value="draft" <?= 'draft' === $value( 'status' ) ? 'selected' : '' ?>>Draft</option>
			</select>
		</div>
	</div>

	<?php if ( Schedule::MODE_PLANNED === $mode ) : ?>
		<div class="panel">
			<h3>Planned schedule</h3>
			<p class="muted">
				Ordering opens and closes automatically around this date, using the
				rules in Settings.
			</p>

			<div class="field" style="max-width:260px">
				<label for="service_date">Service date</label>
				<input type="date" id="service_date" name="service_date" value="<?= e( $value( 'service_date' ) ) ?>">
			</div>
		</div>
	<?php elseif ( Schedule::MODE_ON_PUBLISH === $mode ) : ?>
		<div class="panel">
			<h3>On publish</h3>
			<p class="muted">
				Saving this as published opens ordering straight away, running to the
				next cutoff.
			</p>

			<?php if ( $item && '' !== $item['opened_at'] ) : ?>
				<p>Opened <?= e( $item['opened_at'] ) ?> UTC.</p>
				<div class="field">
					<label><input type="checkbox" name="reopen" value="1"> Reopen from now</label>
				</div>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="panel">
			<h3>Manual window</h3>
			<p class="muted">Both fields are needed for this dish to be orderable.</p>

			<div class="field-row">
				<div>
					<label for="open_from">Orderable from</label>
					<input type="datetime-local" id="open_from" name="open_from" value="<?= e( $toInput( (string) $value( 'open_from' ) ) ) ?>">
				</div>
				<div>
					<label for="close_at">Until</label>
					<input type="datetime-local" id="close_at" name="close_at" value="<?= e( $toInput( (string) $value( 'close_at' ) ) ) ?>">
				</div>
				<div>
					<label for="service_date_manual">Served on</label>
					<input type="date" id="service_date_manual" name="service_date" value="<?= e( $value( 'service_date' ) ) ?>">
					<p class="help">Blank derives it from the closing time.</p>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( Schedule::MODE_MANUAL !== $mode ) : ?>
		<details class="panel">
			<summary>Override this dish</summary>
			<p class="muted" style="margin-top:12px">
				Setting both overrides the schedule for this dish alone. Clear both to
				go back to the normal rules.
			</p>

			<div class="field-row">
				<div>
					<label for="open_from_o">Orderable from</label>
					<input type="datetime-local" id="open_from_o" name="open_from" value="<?= e( $toInput( (string) $value( 'open_from' ) ) ) ?>">
				</div>
				<div>
					<label for="close_at_o">Until</label>
					<input type="datetime-local" id="close_at_o" name="close_at" value="<?= e( $toInput( (string) $value( 'close_at' ) ) ) ?>">
				</div>
			</div>
		</details>
	<?php endif; ?>

	<button type="submit">Save dish</button>
	<a class="button button--quiet" href="/admin/menu">Cancel</a>
</form>
