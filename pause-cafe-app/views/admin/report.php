<?php
use PauseCafe\Blackouts;
use PauseCafe\Schedule;

$total = 0;

foreach ( $summary as $dishes ) {
	foreach ( $dishes as $dish ) {
		$total += $dish['qty'];
	}
}
?>

<h1>Kitchen report</h1>

<form method="get" action="/admin/report" class="field-row no-print" style="max-width:640px">
	<div>
		<label for="date">Serving</label>
		<select id="date" name="date" onchange="this.form.submit()">
			<?php foreach ( array_reverse( $dates ) as $date ) : ?>
				<option value="<?= e( $date ) ?>" <?= $date === $serviceDate ? 'selected' : '' ?>>
					<?= e( Schedule::formatDate( $date, 'l j F Y' ) ) ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<div style="align-self:end; display:flex; gap:10px">
		<a class="button button--quiet" href="/admin/report/export?date=<?= e( urlencode( $serviceDate ) ) ?>">Download CSV</a>
		<button type="button" class="button button--quiet" onclick="window.print()">Print</button>
	</div>
</form>

<h2><?= e( Schedule::formatDate( $serviceDate, 'l j F Y' ) ) ?></h2>

<?php if ( $serviceDate && Blackouts::isBlackout( $serviceDate ) ) : ?>
	<div class="flash flash--notice"><?= e( Blackouts::label( $serviceDate ) ) ?></div>
<?php endif; ?>

<?php if ( ! $summary ) : ?>
	<p class="muted">No orders for this date.</p>
<?php else : ?>

	<?php foreach ( $summary as $location => $dishes ) : ?>
		<?php
		$locationTotal = 0;

		foreach ( $dishes as $dish ) {
			$locationTotal += $dish['qty'];
		}
		?>

		<h3><?= e( $location ) ?> <span class="muted"><?= (int) $locationTotal ?> meals</span></h3>

		<table>
			<thead>
				<tr><th>Dish</th><th class="num">Qty</th><th>For</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $dishes as $dish => $detail ) : ?>
					<tr>
						<td><strong><?= e( $dish ) ?></strong></td>
						<td class="num"><?= (int) $detail['qty'] ?></td>
						<td class="muted"><?= e( implode( ', ', $detail['people'] ) ) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>

	<p><strong><?= (int) $total ?> meals in total.</strong></p>

<?php endif; ?>
