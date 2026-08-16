<?php
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Orders;
use PauseCafe\Schedule;
?>

<h1>Trash</h1>

<p class="muted">
	Orders on their way out. They are already out of the cook list, out of what is
	still to collect, and no longer holding portions — but nothing has been
	refunded and nothing has been destroyed. <a href="/admin/orders">Back to orders</a>.
</p>

<?php if ( ! $orders ) : ?>

	<div class="panel" style="text-align:center">
		<p class="muted">The trash is empty.</p>
	</div>

<?php else : ?>

	<div class="flash flash--notice">
		<strong>Deleting for good is not the same as cancelling.</strong>
		Cancelling says an order happened and was undone, and leaves both halves on
		the record. Deleting says it never happened: the order, its lines and every
		wallet entry it wrote all go, and whatever was charged returns to that
		member's balance. It is meant for orders put there while testing.
	</div>

	<div class="table-scroll">
		<table>
			<thead>
				<tr>
					<th>Order</th><th>Who</th><th>For</th>
					<th class="num">Worth</th><th class="num">Still on the ledger</th><th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<?php
					$orderId = (int) $order['id'];

					/*
					 * What deleting would actually claw back. Not the same as the
					 * total: an order already refunded in part has less than its
					 * face value still sitting against the member.
					 */
					$held = (int) $order['charged_cents'] - Orders::refundedCents( $orderId );
					?>
					<tr>
						<td>
							<strong>#<?= $orderId ?></strong><br>
							<span class="muted">
								was <?= e( '' !== $order['restore_status'] ? $order['restore_status'] : 'confirmed' ) ?>
							</span>
						</td>
						<td>
							<?= e( $order['user_name'] ) ?><br>
							<span class="muted"><?= e( $order['user_email'] ) ?></span>
						</td>
						<td class="muted">
							<?= e( '' !== $order['service_date'] ? Schedule::formatDate( $order['service_date'], 'j M Y' ) : '—' ) ?>
						</td>
						<td class="num"><?= e( Money::format( (int) $order['total_cents'] ) ) ?></td>
						<td class="num">
							<?= e( Money::format( $held ) ) ?>
							<?php if ( $held > 0 ) : ?>
								<br><span class="muted">comes back on delete</span>
							<?php endif; ?>
						</td>
						<td class="num">
							<?php // Two buttons, one form: only the pressed one submits its value. ?>
							<form method="post" action="/admin/orders/trash">
								<?= Csrf::field() ?>
								<input type="hidden" name="id" value="<?= $orderId ?>">

								<button type="submit" name="action" value="restore" class="button button--quiet">
									Restore
								</button>

								<button type="submit" name="action" value="purge" class="link-button"
									onclick="return confirm('Delete order #<?= $orderId ?> for good? Its wallet entries go too, and <?= e( Money::format( $held ) ) ?> returns to <?= e( $order['user_name'] ) ?>. This cannot be undone.')">
									Delete for good
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

<?php endif; ?>
