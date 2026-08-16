<?php
/**
 * Orders for one serving date.
 *
 * The whole table is one form so the tick-boxes can drive several actions,
 * each button choosing its own destination with formaction — the same shape as
 * the cart, and for the same reason: anything ticked has to survive whichever
 * button gets pressed.
 */

use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Orders;
use PauseCafe\Payments;
use PauseCafe\Schedule;

$owing     = 0;
$cancelled = 0;

foreach ( $orders as $orderRow ) {
	if ( Orders::STATUS_CANCELLED === $orderRow['status'] ) {
		++$cancelled;

		continue;
	}

	if ( ! Orders::isPaid( $orderRow ) ) {
		$owing += (int) $orderRow['total_cents'];
	}
}

?>

<h1>Orders</h1>

<?php if ( $owing > 0 ) : ?>
	<div class="flash flash--notice">
		<?= e( Money::format( $owing ) ) ?> still to collect for this date.
	</div>
<?php endif; ?>

<?php if ( $retired ) : ?>
	<?php
	/*
	 * The case that used to be invisible: a dish deleted after somebody had
	 * ordered it is drafted rather than removed, and its date could vanish from
	 * this picker entirely, taking the orders and the money with it.
	 */
	?>
	<div class="flash flash--notice">
		No longer on the menu for this date:
		<strong><?= e( implode( ', ', $retired ) ) ?></strong>.
		The orders below still stand — cancel them here if they should not be cooked.
	</div>
<?php endif; ?>

<form method="get" action="/admin/orders" class="field-row no-print" style="max-width:640px">
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
	<div>
		<label for="status">Showing</label>
		<select id="status" name="status" onchange="this.form.submit()">
			<option value="confirmed" <?= Orders::STATUS_CONFIRMED === $status ? 'selected' : '' ?>>Live orders</option>
			<option value="cancelled" <?= Orders::STATUS_CANCELLED === $status ? 'selected' : '' ?>>Cancelled</option>
			<option value="all" <?= 'all' === $status ? 'selected' : '' ?>>Both</option>
		</select>
	</div>
	<div style="align-self:end">
		<a class="button" href="/admin/orders/new?date=<?= e( urlencode( $serviceDate ) ) ?>">Order for someone</a>
	</div>
	<div style="align-self:end">
		<?php // Its own screen, not a filter: the trash spans every date. ?>
		<a class="button button--quiet" href="/admin/orders/trash">Trash<?= $trashCount ? ' (' . (int) $trashCount . ')' : '' ?></a>
	</div>
</form>

<?php if ( ! $orders ) : ?>
	<p class="muted">
		<?= Orders::STATUS_CANCELLED === $status ? 'Nothing cancelled for this date.' : 'No orders for this date.' ?>
	</p>
<?php else : ?>

	<form method="post" action="/admin/orders/bulk" id="orders-form">
		<?= Csrf::field() ?>
		<input type="hidden" name="date" value="<?= e( $serviceDate ) ?>">
		<input type="hidden" name="status" value="<?= e( $status ) ?>">

		<div class="table-scroll">
			<table>
				<thead>
					<tr>
						<th class="no-print" style="width:1%">
							<?php // Ticks every box in the table; no JS beyond this one line. ?>
							<input type="checkbox" aria-label="Select all"
								onclick="this.closest('table').querySelectorAll('input[name=\'ids[]\']').forEach(b => b.checked = this.checked)">
						</th>
						<th>Order</th>
						<th>Account</th>
						<th>Group</th>
						<th>Payment</th>
						<th class="num">Total</th>
						<th class="no-print"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<?php $isCancelled = Orders::STATUS_CANCELLED === $order['status']; ?>
						<tr<?= $isCancelled ? ' class="is-cancelled"' : '' ?>>
							<td class="no-print">
								<input type="checkbox" name="ids[]" value="<?= (int) $order['id'] ?>"
									aria-label="Select order <?= (int) $order['id'] ?>">
							</td>
							<td>
								<a href="/orders/<?= (int) $order['id'] ?>">#<?= (int) $order['id'] ?></a>
								<?php if ( $isCancelled ) : ?>
									<br><span class="pill pill--past">Cancelled</span>
								<?php endif; ?>
							</td>
							<td>
								<?= e( $order['user_name'] ) ?><br>
								<span class="muted"><?= e( $order['user_email'] ) ?></span>
							</td>
							<td><?= e( $order['user_group'] ) ?></td>
							<td>
								<?= e( Payments::label( (string) $order['payment_method'] ) ) ?><br>
								<?php if ( $isCancelled ) : ?>
									<span class="muted">—</span>
								<?php elseif ( Orders::isPaid( $order ) ) : ?>
									<span class="pill pill--open">Paid</span>
								<?php else : ?>
									<span class="pill pill--closed">Owing</span>
								<?php endif; ?>
							</td>
							<td class="num"><?= e( Money::format( (int) $order['total_cents'] ) ) ?></td>
							<td class="no-print">
								<?php // A plain link, so it works from inside the bulk form. ?>
								<a href="/admin/orders/<?= (int) $order['id'] ?>/edit">Edit</a><br>

								<?php if ( ! $isCancelled ) : ?>
									<?php
									/*
									 * One order, one click, still — the tick boxes are for
									 * doing several at once, not a replacement for acting on
									 * one.
									 *
									 * These live inside the bulk form, which cannot contain
									 * another form. formaction picks the route and the
									 * button's own name/value carries the state, because a
									 * submit button only submits the one that was pressed —
									 * so every row can offer its own without the names
									 * colliding.
									 */
									?>
									<button type="submit" class="link-button" name="state"
										value="<?= Orders::isPaid( $order ) ? 'unpaid' : 'paid' ?>"
										formaction="/admin/orders/<?= (int) $order['id'] ?>/paid">
										<?= Orders::isPaid( $order ) ? 'Mark unpaid' : 'Mark paid' ?>
									</button>

									<button type="submit" class="link-button"
										formaction="/admin/orders/<?= (int) $order['id'] ?>/cancel"
										onclick="return confirm('Cancel this order?')">Cancel</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="panel no-print">
			<h3>With the ticked orders</h3>
			<p class="muted">
				<?= count( $orders ) ?> shown<?= $cancelled > 0 ? ', ' . (int) $cancelled . ' of them cancelled' : '' ?>.
				Nothing happens to an order you have not ticked.
			</p>

			<div class="field-row">
				<div>
					<button type="submit" name="action" value="paid" class="button button--quiet">Mark paid</button>
				</div>
				<div>
					<button type="submit" name="action" value="unpaid" class="button button--quiet">Mark unpaid</button>
				</div>
				<div>
					<button type="submit" name="action" value="export" class="button button--quiet">Download CSV</button>
				</div>
				<div>
					<button type="submit" name="action" value="resend" class="button button--quiet">Resend confirmation</button>
				</div>
				<div>
					<?php
					/*
					 * Trashing moves no money on purpose, and says so, because
					 * "move to trash" reads like tidying up rather than like a
					 * refund. Cancelling is what gives money back.
					 */
					?>
					<button type="submit" name="action" value="trash" class="button button--quiet"
						onclick="return confirm('Move every ticked order to the trash? Nothing is refunded — cancel first if money should go back.')">
						Move to trash
					</button>

					<?php // Moves money, so it asks -- and says how much before it does. ?>
					<button type="submit" name="action" value="cancel" class="button button--danger"
						onclick="return confirm('Cancel every ticked order? Wallet payments are refunded and everyone affected is emailed.')">
						Cancel orders
					</button>
				</div>
			</div>
		</div>
	</form>

<?php endif; ?>
