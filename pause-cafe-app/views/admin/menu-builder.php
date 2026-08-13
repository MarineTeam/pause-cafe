<?php
use PauseCafe\Csrf;
use PauseCafe\Schedule;

include __DIR__ . '/_tabs.php';

/**
 * The datalist gives native autocomplete with no JavaScript. Typing a name that
 * already exists carries its price and description across on save.
 */
$datalist = static function ( array $names ): string {
	$options = '';

	foreach ( $names as $name ) {
		$options .= '<option value="' . e( $name ) . '"></option>';
	}

	return '<datalist id="dish-names">' . $options . '</datalist>';
};

$toInput = static function ( string $stored ): string {
	$parsed = Schedule::parseDateTime( $stored );

	return $parsed ? $parsed->format( 'Y-m-d\TH:i' ) : '';
};
?>

<h1>Build menu</h1>

<p class="muted">
	Fill the grid and save. For one dish at a time — a price, a photo, a portion
	limit — use <a href="/admin/menu">the menu list</a> instead. Both edit the
	same dishes.
</p>

<?php if ( count( $schedules ) > 1 ) : ?>
	<form method="get" action="/admin/menu/builder" class="field-row no-print" style="max-width:520px">
		<div>
			<label for="schedule">Schedule</label>
			<select id="schedule" name="schedule" onchange="this.form.submit()">
				<?php foreach ( $schedules as $id => $option ) : ?>
					<option value="<?= (int) $id ?>" <?= (int) $id === (int) $scheduleId ? 'selected' : '' ?>>
						<?= e( $option['name'] ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<input type="hidden" name="month" value="<?= e( $month ) ?>">
	</form>
<?php endif; ?>

<p class="muted">
	<strong><?= e( $rules['name'] ) ?></strong> —
	<?= e( Schedule::modes()[ $mode ] ?? $mode ) ?>.
	<a href="/admin/schedules">Change its rules</a>.
</p>

<?= $datalist( $names ) ?>

<?php if ( Schedule::MODE_ON_PUBLISH === $mode ) : ?>

	<div class="panel">
		<h2>This week's menu</h2>
		<p class="muted">
			Ordering is on the publish-when-ready setting, so there is no calendar to
			fill in. Saving this puts the dishes live and opens ordering straight
			away.
		</p>

		<form method="post" action="/admin/menu/builder">
			<?= Csrf::field() ?>
			<input type="hidden" name="schedule" value="<?= (int) $scheduleId ?>">

			<table class="widefat">
				<thead>
					<tr><th style="width:16em">Pickup</th><th>Dish</th></tr>
				</thead>
				<tbody>
					<?php foreach ( $locations as $location ) : ?>
						<?php $item = $live[ (int) $location['id'] ] ?? null; ?>
						<tr>
							<td><strong><?= e( $location['name'] ) ?></strong></td>
							<td>
								<input type="text" list="dish-names" style="width:100%"
									name="dish[<?= (int) $location['id'] ?>]"
									value="<?= e( $item['name'] ?? '' ) ?>" placeholder="Start typing…">
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<button type="submit">Publish menu and open ordering</button>
		</form>
	</div>

<?php else : ?>

	<div class="kitchen-head">
		<h2><?= e( $monthName ) ?></h2>
		<div class="no-print">
			<a class="button button--quiet" href="/admin/menu/builder?schedule=<?= (int) $scheduleId ?>&amp;month=<?= e( $previous ) ?>">&laquo; Previous</a>
			<a class="button button--quiet" href="/admin/menu/builder?schedule=<?= (int) $scheduleId ?>&amp;month=<?= e( $next ) ?>">Next &raquo;</a>
		</div>
	</div>

	<?php if ( ! $rows ) : ?>

		<p class="muted">
			No service days fall in this month. Check the service weekday in
			<a href="/admin/settings">Settings</a>.
		</p>

	<?php else : ?>

		<form method="post" action="/admin/menu/builder">
			<?= Csrf::field() ?>
			<input type="hidden" name="schedule" value="<?= (int) $scheduleId ?>">
			<input type="hidden" name="month" value="<?= e( $month ) ?>">

			<div class="table-scroll">
				<table class="kitchen-table">
					<thead>
						<tr>
							<th style="width:11em">Serving</th>
							<?php if ( Schedule::MODE_MANUAL === $mode ) : ?>
								<th style="width:14em">Orderable from</th>
								<th style="width:14em">Until</th>
							<?php endif; ?>
							<?php foreach ( $locations as $location ) : ?>
								<th><?= e( $location['name'] ) ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $date => $cells ) : ?>
							<?php
							$past  = $date < $today;
							$first = null;

							foreach ( $cells as $candidate ) {
								if ( $candidate ) {
									$first = $candidate;
									break;
								}
							}
							?>
							<tr<?= $past ? ' class="pcm-row--past" style="opacity:.6"' : '' ?>>
								<td>
									<strong><?= e( Schedule::formatDate( (string) $date, 'j M' ) ) ?></strong><br>
									<span class="muted" style="font-size:13px">
										<?= e( Schedule::formatDate( (string) $date, 'l' ) ) ?>
										<?= $past ? ' · served' : '' ?>
									</span>
								</td>

								<?php if ( Schedule::MODE_MANUAL === $mode ) : ?>
									<td>
										<?php if ( $past ) : ?>
											<span class="muted">—</span>
										<?php else : ?>
											<input type="datetime-local" name="from[<?= e( $date ) ?>]"
												value="<?= e( $toInput( (string) ( $first['open_from'] ?? '' ) ) ) ?>">
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $past ) : ?>
											<span class="muted">—</span>
										<?php else : ?>
											<input type="datetime-local" name="until[<?= e( $date ) ?>]"
												value="<?= e( $toInput( (string) ( $first['close_at'] ?? '' ) ) ) ?>">
										<?php endif; ?>
									</td>
								<?php endif; ?>

								<?php foreach ( $locations as $location ) : ?>
									<?php $item = $cells[ (int) $location['id'] ] ?? null; ?>
									<td>
										<?php if ( $past ) : ?>
											<?= $item ? e( $item['name'] ) : '<span class="muted">—</span>' ?>
										<?php else : ?>
											<input type="text" list="dish-names" style="width:100%"
												name="dish[<?= e( $date ) ?>][<?= (int) $location['id'] ?>]"
												value="<?= e( $item['name'] ?? '' ) ?>" placeholder="Start typing…">

											<?php if ( $item ) : ?>
												<a class="pclm-edit-link" style="font-size:12px"
													href="/admin/menu/<?= (int) $item['id'] ?>">Edit</a>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p class="description muted">
				Clearing a name moves that dish to draft rather than deleting it, so
				past orders keep their history. Days already served are read-only.
				A new dish takes its price and description from the last one with the
				same name.
			</p>

			<button type="submit">Save month</button>
		</form>

	<?php endif; ?>

<?php endif; ?>
