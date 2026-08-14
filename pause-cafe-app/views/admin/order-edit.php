<?php
/**
 * One order, editable.
 *
 * Every control is its own small form posting the same action, so a mis-click
 * changes one thing rather than everything on the screen. Money is involved, so
 * each button says what it will do before it does it.
 */

use PauseCafe\Csrf;
use PauseCafe\MenuFields;
use PauseCafe\Money;
use PauseCafe\Orders;
use PauseCafe\Payments;
use PauseCafe\Schedule;

$cancelled = 'cancelled' === $order['status'];
$byWallet  = 'wallet' === (string) $order['payment_method'];
$charged   = (int) $order['charged_cents'];
$refunded  = Orders::refundedCents( (int) $order['id'] );
?>

<h1>Order #<?= (int) $order['id'] ?></h1>

<p class="muted">
	<?= e( $order['user_name'] ?? '' ) ?>
	· <?= e( Schedule::formatDate( (string) $order['service_date'], 'l j F Y' ) ) ?>
	· <?= e( Payments::label( (string) $order['payment_method'] ) ) ?>
	<?php if ( $byWallet ) : ?>
		· wallet balance <?= e( Money::format( (int) $balance ) ) ?>
	<?php endif; ?>
</p>

<?php if ( $cancelled ) : ?>
	<div class="flash flash--notice">
		This order is cancelled. It is here to look at, not to change.
	</div>
<?php endif; ?>

<div class="stat-row">
	<div class="stat">
		<p class="stat__label">Food now</p>
		<p class="stat__value"><?= e( Money::format( (int) $order['total_cents'] ) ) ?></p>
	</div>
	<div class="stat">
		<p class="stat__label">Taken so far</p>
		<p class="stat__value"><?= e( Money::format( $charged ) ) ?></p>
	</div>
	<div class="stat">
		<p class="stat__label">Given back</p>
		<p class="stat__value"><?= e( Money::format( $refunded ) ) ?></p>
	</div>
	<div class="stat">
		<p class="stat__label">Can still refund</p>
		<p class="stat__value"><?= e( Money::format( (int) $refundable ) ) ?></p>
	</div>
</div>

<div class="panel">
	<h3>What was ordered</h3>

	<?php if ( ! $lines ) : ?>
		<p class="muted">Every line has been removed. The order is worth nothing — cancel it if it is not wanted.</p>
	<?php endif; ?>

	<?php foreach ( $lines as $line ) : ?>
		<div class="order-line">
			<h4><?= e( $line['item_name'] ) ?>
				<span class="muted"><?= e( Money::format( (int) $line['unit_price_cents'] ) ) ?> each</span>
			</h4>

			<?php if ( ! $cancelled ) : ?>
				<div class="field-row">
					<form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/edit" class="field-row"
						style="flex:1 1 240px; align-items:end">
						<?= Csrf::field() ?>
						<input type="hidden" name="action" value="qty">
						<input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">

						<div style="flex:0 0 7em">
							<label for="qty-<?= (int) $line['id'] ?>">How many</label>
							<input type="number" id="qty-<?= (int) $line['id'] ?>" name="qty" min="0"
								value="<?= (int) $line['qty'] ?>">
						</div>

						<div>
							<?php // Zero removes it, which the button says rather than leaving to be discovered. ?>
							<button type="submit" class="button button--quiet">Change quantity</button>
							<p class="help">Zero removes the line. The difference is refunded.</p>
						</div>
					</form>

					<form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/edit"
						style="flex:2 1 320px">
						<?= Csrf::field() ?>
						<input type="hidden" name="action" value="details">
						<input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">

						<?php
						$of = array(
							'fields' => MenuFields::visibleFor(
								$line['menu_item_id'] ? \PauseCafe\Menu::item( (int) $line['menu_item_id'] ) : null
							),
							'values' => array_merge(
								array(
									MenuFields::PERSON => $line['person_name'],
									MenuFields::GROUP  => $line['group_name'],
									MenuFields::NOTE   => $line['note'],
								),
								(array) json_decode( (string) $line['extra_fields'], true )
							),
							'prefix' => 'line' . (int) $line['id'],
						);

						include \PauseCafe\View::locate( 'partials/order-fields' );
						?>

						<button type="submit" class="button button--quiet">Save details</button>
						<p class="help">Names, groups and notes only. Nothing is charged or refunded.</p>
					</form>
				</div>
			<?php else : ?>
				<p class="muted">
					<?= (int) $line['qty'] ?> ×
					<?= e( '' !== $line['person_name'] ? $line['person_name'] : 'no name' ) ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>

<?php if ( ! $cancelled ) : ?>

	<div class="panel">
		<h3>Add a dish</h3>

		<?php if ( ! $available ) : ?>
			<p class="muted">Nothing else is on the menu for that day.</p>
		<?php else : ?>
			<form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/edit" class="field-row">
				<?= Csrf::field() ?>
				<input type="hidden" name="action" value="add">

				<div style="flex:2 1 260px">
					<label for="menu_item_id">Dish</label>
					<select id="menu_item_id" name="menu_item_id">
						<?php foreach ( $available as $choice ) : ?>
							<option value="<?= (int) $choice['id'] ?>">
								<?= e( $choice['name'] ) ?>
								— <?= e( $choice['location_name'] ) ?>
								<?= e( Money::format( (int) $choice['price_cents'] ) ) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div style="flex:0 0 7em">
					<label for="add-qty">How many</label>
					<input type="number" id="add-qty" name="qty" min="1" value="1">
				</div>

				<div style="align-self:end">
					<button type="submit">Add and charge</button>
				</div>
			</form>

			<p class="help">
				<?= $byWallet
					? 'Comes out of their wallet, which holds ' . e( Money::format( (int) $balance ) ) . '.'
					: 'Added to what they owe on the day.' ?>
				Portion limits still apply.
			</p>
		<?php endif; ?>
	</div>

	<div class="panel">
		<h3>Refund something else</h3>
		<p class="muted">
			For anything the lines cannot say — a discount, or putting something right.
			At most <?= e( Money::format( (int) $refundable ) ) ?>, which is what is left of what they paid.
		</p>

		<form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/edit" class="field-row">
			<?= Csrf::field() ?>
			<input type="hidden" name="action" value="refund">

			<div style="flex:0 0 9em">
				<label for="amount">Amount</label>
				<input type="text" id="amount" name="amount" placeholder="2.50">
			</div>

			<div style="flex:2 1 260px">
				<label for="reason">What for</label>
				<input type="text" id="reason" name="reason" maxlength="200" placeholder="Collected late, meal was cold">
			</div>

			<div style="align-self:end">
				<button type="submit" class="button button--danger">Refund</button>
			</div>
		</form>
	</div>

<?php endif; ?>

<div class="panel">
	<h3>What has happened to the money</h3>

	<?php if ( ! $adjustments ) : ?>
		<p class="muted">Nothing has changed since it was placed.</p>
	<?php else : ?>
		<table>
			<thead>
				<tr><th>When</th><th>What</th><th>Who</th><th class="num">Amount</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $adjustments as $entry ) : ?>
					<tr>
						<td class="muted"><?= e( $entry['created_at'] ) ?></td>
						<td><?= e( $entry['reason'] ) ?></td>
						<td class="muted"><?= e( $entry['by_name'] ?? '—' ) ?></td>
						<td class="num">
							<?php $delta = (int) $entry['delta_cents']; ?>
							<span class="pill <?= $delta < 0 ? 'pill--open' : 'pill--closed' ?>">
								<?= $delta < 0 ? '−' : '+' ?><?= e( Money::format( abs( $delta ) ) ) ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="help">
			Minus is money back to them, plus is money taken.
			<?= $byWallet
				? 'Each one has a matching line on their wallet statement.'
				: 'Cash changes hands in person — these are the record of what is owed either way.' ?>
		</p>
	<?php endif; ?>
</div>

<p><a class="button button--quiet"
	href="/admin/orders?date=<?= e( urlencode( (string) $order['service_date'] ) ) ?>">Back to orders</a></p>
