<?php
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;

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

<?php // enctype is what carries the picture; without it the file silently never arrives. ?>
<form method="post" action="/admin/menu/save" enctype="multipart/form-data">
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
			<label for="image">Picture <span class="muted">(optional)</span></label>

			<?php $currentImage = $value( 'image_path' ); ?>

			<?php if ( '' !== $currentImage ) : ?>
				<p><img src="<?= e( $currentImage ) ?>" alt="" style="max-width:220px; border-radius:var(--radius)"></p>
				<label style="font-weight:400">
					<input type="checkbox" name="remove_image" value="1">
					Remove this picture
				</label>
			<?php endif; ?>

			<input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">

			<p class="help">
				JPEG, PNG, GIF or WebP, under 6&nbsp;MB. It is resized on upload, so a
				photo straight off a phone is fine.
				<?= '' !== $currentImage ? 'Choosing a new one replaces the current picture.' : 'A dish without one still looks right.' ?>
			</p>
		</div>

		<div class="field">
			<label for="status">Status</label>
			<select id="status" name="status">
				<option value="published" <?= 'draft' !== $value( 'status', 'published' ) ? 'selected' : '' ?>>Published</option>
				<option value="draft" <?= 'draft' === $value( 'status' ) ? 'selected' : '' ?>>Draft</option>
			</select>
		</div>
	</div>

	<div class="panel">
		<h3>When it is served</h3>

		<div class="field-row">
			<div>
				<label for="schedule_id">Schedule</label>
				<select id="schedule_id" name="schedule_id">
					<?php foreach ( $schedules as $scheduleId => $rules ) : ?>
						<option value="<?= (int) $scheduleId ?>"
							<?= (int) $scheduleId === (int) $value( 'schedule_id', (string) \PauseCafe\Schedules::DEFAULT_ID ) ? 'selected' : '' ?>>
							<?= e( $rules['name'] ) ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="help">
					Which rhythm this dish follows. Without this field every dish added
					here landed on the default schedule, so the only way to give one its
					own timing was to change the schedule everything else uses.
				</p>
			</div>

			<div>
				<label style="font-weight:400; margin-top:26px; display:block">
					<input type="checkbox" name="standalone" value="1"
						<?= '' !== $value( 'standalone' ) && '0' !== $value( 'standalone' ) ? 'checked' : '' ?>>
					Show whenever it can be ordered
				</label>
				<p class="help">
					For a one-off — a Christmas menu, a box of chocolates, something with
					two weeks' notice. It ignores the weekly rhythm entirely, appears in
					its own section while the dates below allow it, and cannot affect any
					other menu. Set the from and until dates yourself.
				</p>
			</div>
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

	<div class="panel">
		<?php
		$fr = array(
			'rules' => \PauseCafe\MenuFields::decodeRules( (string) $value( 'field_rules' ) ),
			'note'  => 'Overrides this dish only. Inherit follows its schedule, which follows the site default.',
		);

		include \PauseCafe\View::locate( 'partials/field-rules' );
		?>
	</div>

	<?php if ( $affected > 0 ) : ?>
		<div class="panel">
			<h3>People who have already ordered this</h3>
			<p class="muted">
				<?= (int) $affected ?>
				<?= 1 === (int) $affected ? 'person has' : 'people have' ?>
				a confirmed order for this dish.
			</p>

			<?php
			/*
			 * An unticked checkbox is not submitted, so this hidden field is what
			 * tells the server the control was on the form at all.
			 */
			?>
			<input type="hidden" name="notify_present" value="1">

			<div class="field">
				<label>
					<input type="checkbox" name="notify_orders" value="1" checked>
					Email them about this change
				</label>
				<p class="help">
					Leave it ticked unless you are fixing something they would not
					notice. Either way, renaming the dish updates their order so the
					kitchen list stays consistent, and nothing more is charged.
				</p>
			</div>
		</div>
	<?php endif; ?>

	<button type="submit">Save dish</button>
	<a class="button button--quiet" href="/admin/menu">Cancel</a>
</form>
