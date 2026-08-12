<?php
use PauseCafe\Money;
use PauseCafe\Schedule;

include __DIR__ . '/_tabs.php';

$meals = 0;

foreach ( $summary as $dishes ) {
	foreach ( $dishes as $dish ) {
		$meals += $dish['qty'];
	}
}
?>

<h1>Overview</h1>

<div class="stat-row">
	<div class="stat">
		<p class="stat__label">Serving</p>
		<p class="stat__value"><?= $serviceDate ? e( Schedule::formatDate( $serviceDate, 'j M' ) ) : '—' ?></p>
	</div>
	<div class="stat">
		<p class="stat__label">Meals ordered</p>
		<p class="stat__value"><?= (int) $meals ?></p>
	</div>
	<div class="stat">
		<p class="stat__label">Waiting for approval</p>
		<p class="stat__value"><?= (int) $pending ?></p>
	</div>
	<div class="stat">
		<p class="stat__label">Held in wallets</p>
		<p class="stat__value"><?= e( Money::format( (int) $outstanding ) ) ?></p>
	</div>
</div>

<?php if ( $pending > 0 ) : ?>
	<div class="flash flash--notice">
		<?= (int) $pending ?> <?= 1 === (int) $pending ? 'person is' : 'people are' ?> waiting to be approved.
		<a href="/admin/users">Review them</a>.
	</div>
<?php endif; ?>

<div class="panel">
	<h3>Ordering mode</h3>
	<p class="muted"><?= e( Schedule::modes()[ $mode ] ?? $mode ) ?></p>
	<p><a class="button button--quiet" href="/admin/settings">Change</a></p>
</div>

<h2>This week's cook list</h2>

<?php if ( ! $summary ) : ?>
	<p class="muted">No orders yet.</p>
<?php else : ?>
	<?php foreach ( $summary as $location => $dishes ) : ?>
		<h3><?= e( $location ) ?></h3>
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

	<p><a class="button" href="/kitchen">Full kitchen list</a></p>
<?php endif; ?>
