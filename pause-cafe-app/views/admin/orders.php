<?php
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Orders;
use PauseCafe\Schedule;

include __DIR__ . '/_tabs.php';

$owing = 0;

foreach ( $orders as $orderRow ) {
	if ( ! Orders::isPaid( $orderRow ) ) {
		$owing += (int) $orderRow['total_cents'];
	}
}
?>

<?php if ( $owing > 0 ) : ?>
	<div class="flash flash--notice">
		<?= e( Money::format( $owing ) ) ?> still to collect for this date.
	</div>
<?php endif; ?>

<h1>Orders</h1>

<form method="get" action="/admin/orders" class="field-row no-print" style="max-width:520px">
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
	<div style="align-self:end">
		<a class="button" href="/admin/orders/new?date=<?= e( urlencode( $serviceDate ) ) ?>">Order for someone</a>
	</div>
</form>

<?php if ( ! $orders ) : ?>
	<p class="muted">No orders for this date.</p>
<?php else : ?>
	<div class="table-scroll">
		<table>
			<thead>
				<tr>
					<th>Order</th>
					<th>Account</th>
					<th>Group</th>
					<th>Payment</th>
					<th class="num">Total</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<tr>
						<td><a href="/orders/<?= (int) $order['id'] ?>">#<?= (int) $order['id'] ?></a></td>
						<td>
							<?= e( $order['user_name'] ) ?><br>
							<span class="muted"><?= e( $order['user_email'] ) ?></span>
						</td>
						<td><?= e( $order['user_group'] ) ?></td>
						<td>
							<?= e( \PauseCafe\Payments::label( (string) $order['payment_method'] ) ) ?><br>
							<?php if ( Orders::isPaid( $order ) ) : ?>
								<span class="pill pill--open">Paid</span>
							<?php else : ?>
								<span class="pill pill--closed">Owing</span>
							<?php endif; ?>
						</td>
						<td class="num"><?= e( Money::format( (int) $order['total_cents'] ) ) ?></td>
						<td class="no-print">
							<form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/paid">
								<?= Csrf::field() ?>
								<input type="hidden" name="state" value="<?= Orders::isPaid( $order ) ? 'unpaid' : 'paid' ?>">
								<button type="submit" class="link-button">
									<?= Orders::isPaid( $order ) ? 'Mark unpaid' : 'Mark paid' ?>
								</button>
							</form>

							<form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/cancel"
								onsubmit="return confirm('Cancel this order?')">
								<?= Csrf::field() ?>
								<button type="submit" class="link-button">Cancel</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
