<?php
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;

include __DIR__ . '/_tabs.php';
?>

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
						<td class="num"><?= e( Money::format( (int) $order['total_cents'] ) ) ?></td>
						<td class="no-print">
							<form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/cancel"
								onsubmit="return confirm('Cancel this order and refund their wallet?')">
								<?= Csrf::field() ?>
								<button type="submit" class="link-button">Cancel and refund</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
