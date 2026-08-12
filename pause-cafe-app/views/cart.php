<?php
use PauseCafe\Auth;
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;

$lines    = $cart['lines'];
$total    = $cart['total'];
$problems = $cart['problems'];
$short    = $balance < $total;
?>

<h1>Your cart</h1>

<?php if ( ! $lines ) : ?>

	<p class="muted">Nothing in the cart yet.</p>
	<p><a class="button" href="/">Back to the menu</a></p>

<?php else : ?>

	<?php foreach ( $problems as $problem ) : ?>
		<div class="flash flash--error"><?= e( $problem ) ?></div>
	<?php endforeach; ?>

	<?php if ( '' !== $cart['serviceDate'] ) : ?>
		<p class="muted">For <?= e( Schedule::formatDate( $cart['serviceDate'], 'l j F' ) ) ?>.</p>
	<?php endif; ?>

	<div class="table-scroll">
		<table>
			<thead>
				<tr>
					<th>Dish</th>
					<th>For</th>
					<th>Group</th>
					<th class="num">Qty</th>
					<th class="num">Subtotal</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lines as $line ) : ?>
					<tr>
						<td>
							<strong><?= e( $line['item']['name'] ) ?></strong><br>
							<span class="muted"><?= e( $line['item']['location_name'] ) ?></span>
						</td>
						<td colspan="3">
							<form method="post" action="/cart/update" class="field-row">
								<?= Csrf::field() ?>
								<input type="hidden" name="index" value="<?= (int) $line['index'] ?>">

								<input type="text" name="person_name" value="<?= e( $line['person_name'] ) ?>"
									aria-label="Name on this meal" required>
								<input type="text" name="group_name" value="<?= e( $line['group_name'] ) ?>"
									aria-label="Group" placeholder="Group">
								<input type="number" name="qty" value="<?= (int) $line['qty'] ?>" min="1"
									aria-label="Quantity" style="max-width:90px">

								<button type="submit" class="button--quiet">Update</button>
							</form>
						</td>
						<td class="num"><?= e( Money::format( $line['subtotal'] ) ) ?></td>
						<td class="num">
							<form method="post" action="/cart/remove">
								<?= Csrf::field() ?>
								<input type="hidden" name="index" value="<?= (int) $line['index'] ?>">
								<button type="submit" class="link-button">Remove</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="panel">
		<table>
			<tr>
				<th>Total</th>
				<td class="num"><strong><?= e( Money::format( $total ) ) ?></strong></td>
			</tr>
			<tr>
				<th>Your balance</th>
				<td class="num <?= $short ? 'muted' : '' ?>"><?= e( Money::format( $balance ) ) ?></td>
			</tr>
			<tr>
				<th>After this order</th>
				<td class="num"><?= e( Money::format( $balance - $total ) ) ?></td>
			</tr>
		</table>

		<?php if ( $short ) : ?>
			<div class="flash flash--notice">
				Your balance does not cover this order. Top up first, or ask an organiser.
			</div>
		<?php endif; ?>

		<form method="post" action="/checkout">
			<?= Csrf::field() ?>
			<button type="submit" <?= ( $problems || $short || ! Auth::canOrder() ) ? 'disabled' : '' ?>>
				Place order
			</button>
		</form>
	</div>

<?php endif; ?>
