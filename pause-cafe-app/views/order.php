<?php
use PauseCafe\Money;
use PauseCafe\Schedule;
?>

<h1>Order #<?= (int) $order['id'] ?></h1>

<p class="muted">
	For <?= e( Schedule::formatDate( $order['service_date'], 'l j F Y' ) ) ?> ·
	<?= e( ucfirst( $order['status'] ) ) ?> ·
	<?= e( \PauseCafe\Payments::label( (string) $order['payment_method'] ) ) ?>
	<?php if ( \PauseCafe\Orders::isPaid( $order ) ) : ?>
		<span class="pill pill--open">Paid</span>
	<?php else : ?>
		<span class="pill pill--closed">To pay on the day</span>
	<?php endif; ?>
	<?php if ( $order['placed_by_name'] ) : ?>
		· placed by <?= e( $order['placed_by_name'] ) ?> on behalf of <?= e( $order['user_name'] ) ?>
	<?php endif; ?>
</p>

<div class="table-scroll">
	<table>
		<thead>
			<tr>
				<th>Dish</th>
				<th>Pickup</th>
				<th>For</th>
				<th>Group</th>
				<th class="num">Qty</th>
				<th class="num">Each</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lines as $line ) : ?>
				<tr>
					<td><?= e( $line['item_name'] ) ?></td>
					<td><?= e( $line['location_name'] ) ?></td>
					<td><?= e( $line['person_name'] ) ?></td>
					<td><?= e( $line['group_name'] ) ?></td>
					<td class="num"><?= (int) $line['qty'] ?></td>
					<td class="num"><?= e( Money::format( (int) $line['unit_price_cents'] ) ) ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<th colspan="5">Total</th>
				<td class="num"><strong><?= e( Money::format( (int) $order['total_cents'] ) ) ?></strong></td>
			</tr>
		</tfoot>
	</table>
</div>

<?php if ( '' !== $order['note'] ) : ?>
	<p class="muted">Note: <?= e( $order['note'] ) ?></p>
<?php endif; ?>

<p><a class="button button--quiet" href="/account">Back to your account</a></p>
