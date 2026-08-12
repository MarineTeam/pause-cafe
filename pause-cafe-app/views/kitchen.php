<?php
use PauseCafe\Auth;
use PauseCafe\Csrf;
use PauseCafe\Kitchen;
use PauseCafe\Orders;
use PauseCafe\Payments;
use PauseCafe\Schedule;

$meals = 0;

foreach ( $totals as $qty ) {
	$meals += $qty;
}

/**
 * A column heading that links to the same view sorted by that column, flipping
 * direction when it is already the active one.
 */
$heading = static function ( string $key, string $label ) use ( $sort, $dir, $query ): string {
	$next  = ( $key === $sort && 'asc' === $dir ) ? 'desc' : 'asc';
	$arrow = $key === $sort ? ( 'asc' === $dir ? ' ▲' : ' ▼' ) : '';

	return '<a href="' . e( Kitchen::url( $query, array( 'sort' => $key, 'dir' => $next ) ) ) . '">' .
		e( $label ) . $arrow . '</a>';
};
?>

<div class="kitchen-head">
	<h1>Kitchen list</h1>

	<div class="no-print">
		<a class="button button--quiet" href="<?= e( Kitchen::url( $query, array(), '/kitchen/export' ) ) ?>">Download CSV</a>
		<button type="button" class="button button--quiet" onclick="window.print()">Print</button>

		<?php if ( ! Auth::isAdmin() ) : ?>
			<form method="post" action="/kitchen/lock" class="inline">
				<?= Csrf::field() ?>
				<button type="submit" class="link-button">Sign out</button>
			</form>
		<?php endif; ?>
	</div>
</div>

<form method="get" action="/kitchen" class="panel no-print">
	<div class="field-row">
		<div>
			<label for="range">When</label>
			<select id="range" name="range" onchange="this.form.from.value='';this.form.to.value='';this.form.submit()">
				<?php foreach ( Kitchen::ranges() as $key => $label ) : ?>
					<option value="<?= e( $key ) ?>" <?= $key === $filters['range'] ? 'selected' : '' ?>>
						<?= e( $label ) ?>
					</option>
				<?php endforeach; ?>
				<?php if ( 'custom' === $filters['range'] ) : ?>
					<option value="custom" selected>Custom</option>
				<?php endif; ?>
			</select>
		</div>

		<div>
			<label for="from">From</label>
			<input type="date" id="from" name="from" value="<?= e( $filters['from'] ) ?>">
		</div>

		<div>
			<label for="to">To</label>
			<input type="date" id="to" name="to" value="<?= e( $filters['to'] ) ?>">
		</div>

		<div>
			<label for="location">Pickup</label>
			<select id="location" name="location">
				<option value="">All</option>
				<?php foreach ( $options['locations'] as $option ) : ?>
					<option value="<?= e( $option ) ?>" <?= $option === $filters['location'] ? 'selected' : '' ?>>
						<?= e( $option ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div>
			<label for="dish">Dish</label>
			<select id="dish" name="dish">
				<option value="">All</option>
				<?php foreach ( $options['dishes'] as $option ) : ?>
					<option value="<?= e( $option ) ?>" <?= $option === $filters['dish'] ? 'selected' : '' ?>>
						<?= e( $option ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div>
			<label for="group">Group</label>
			<select id="group" name="group">
				<option value="">All</option>
				<?php foreach ( $options['groups'] as $option ) : ?>
					<option value="<?= e( $option ) ?>" <?= $option === $filters['group'] ? 'selected' : '' ?>>
						<?= e( $option ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div style="align-self:end; display:flex; gap:8px">
			<button type="submit">Apply</button>
			<a class="button button--quiet" href="/kitchen">Reset</a>
		</div>
	</div>

	<input type="hidden" name="sort" value="<?= e( $sort ) ?>">
	<input type="hidden" name="dir" value="<?= e( $dir ) ?>">
</form>

<?php if ( $totals ) : ?>
	<div class="panel">
		<h2>To cook</h2>
		<ul class="cook-totals">
			<?php foreach ( $totals as $dish => $qty ) : ?>
				<li><strong><?= (int) $qty ?></strong> <?= e( $dish ) ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="muted"><?= (int) $meals ?> meals across <?= count( $rows ) ?> lines.</p>
	</div>
<?php endif; ?>

<?php if ( ! $rows ) : ?>

	<p class="muted">Nothing matches those filters.</p>

<?php else : ?>

	<div class="table-scroll">
		<table class="kitchen-table">
			<thead>
				<tr>
					<th><?= $heading( 'date', 'Date' ) ?></th>
					<th><?= $heading( 'location', 'Pickup' ) ?></th>
					<th><?= $heading( 'dish', 'Dish' ) ?></th>
					<th class="num"><?= $heading( 'qty', 'Qty' ) ?></th>
					<th><?= $heading( 'name', 'Name' ) ?></th>
					<th><?= $heading( 'group', 'Group' ) ?></th>
					<th><?= $heading( 'payment', 'Payment' ) ?></th>
					<th><?= $heading( 'notes', 'Notes' ) ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $line ) : ?>
					<tr>
						<td><?= e( Schedule::formatDate( (string) $line['service_date'], 'j M' ) ) ?></td>
						<td><?= e( $line['location_name'] ) ?></td>
						<td><strong><?= e( $line['item_name'] ) ?></strong></td>
						<td class="num"><?= (int) $line['qty'] ?></td>
						<td>
							<?= e( '' !== $line['person_name'] ? $line['person_name'] : $line['account_name'] ) ?>
							<?php if ( '' !== $line['person_name'] && $line['person_name'] !== $line['account_name'] ) : ?>
								<br><span class="muted" style="font-size:13px">ordered by <?= e( $line['account_name'] ) ?></span>
							<?php endif; ?>
						</td>
						<td><?= e( $line['group_name'] ) ?></td>
						<td>
							<?= e( Payments::label( (string) $line['payment_method'] ) ) ?>
							<?php if ( '' === $line['paid_at'] ) : ?>
								<br><span class="pill pill--closed">Owing</span>
							<?php endif; ?>
						</td>
						<td>
							<?= e( $line['note'] ) ?>
							<?php if ( '' !== $line['order_note'] ) : ?>
								<?php if ( '' !== $line['note'] ) : ?><br><?php endif; ?>
								<span class="muted" style="font-size:13px"><?= e( $line['order_note'] ) ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

<?php endif; ?>
