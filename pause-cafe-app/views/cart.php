<?php
use PauseCafe\Auth;
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;

$lines    = $cart['lines'];
$total    = $cart['total'];
$problems = $cart['problems'];
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

								<?php
								// Same questions the dish asked, so an answer given at the
								// menu can be corrected here.
								$of = array(
									'fields' => \PauseCafe\MenuFields::visibleFor( $line['item'] ),
									'values' => array_merge(
										array(
											\PauseCafe\MenuFields::PERSON => $line['person_name'],
											\PauseCafe\MenuFields::GROUP  => $line['group_name'],
											\PauseCafe\MenuFields::NOTE   => $line['note'],
										),
										$line['extra']
									),
									'prefix' => 'cart' . (int) $line['index'],
								);

								include \PauseCafe\View::locate( 'partials/order-fields' );
								?>

								<div class="field">
									<label for="cart-qty-<?= (int) $line['index'] ?>">Qty</label>
									<input type="number" id="cart-qty-<?= (int) $line['index'] ?>" name="qty"
										value="<?= (int) $line['qty'] ?>" min="1" style="max-width:90px">
								</div>

								<div style="align-self:end">
									<button type="submit" class="button--quiet">Update</button>
								</div>
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

	<?php
	// Each method decides for itself whether it can cover this order, so the
	// cart does not need to know what any of them are.
	$options   = array();
	$firstFree = '';

	foreach ( $methods as $methodId => $method ) {
		$reason = $method->unavailableReason( Auth::id(), $total );

		$options[ $methodId ] = array(
			'method' => $method,
			'reason' => $reason,
		);

		if ( '' === $reason && '' === $firstFree ) {
			$firstFree = $methodId;
		}
	}

	$walletShown = isset( $methods['wallet'] );
	?>

	<div class="panel">
		<table>
			<tr>
				<th>Total</th>
				<td class="num"><strong><?= e( Money::format( $total ) ) ?></strong></td>
			</tr>
			<?php if ( $walletShown ) : ?>
				<tr>
					<th>Your balance</th>
					<td class="num <?= $balance < $total ? 'muted' : '' ?>"><?= e( Money::format( $balance ) ) ?></td>
				</tr>
			<?php endif; ?>
		</table>

		<form method="post" action="/checkout">
			<?= Csrf::field() ?>

			<div class="field">
				<label for="order_note">Order notes <span class="muted">(optional)</span></label>
				<textarea id="order_note" name="order_note" rows="2" maxlength="500"
					placeholder="Anything the kitchen should know about the whole order"></textarea>
			</div>

			<?php if ( ! $options ) : ?>
				<div class="flash flash--error">
					No payment method is switched on. Please tell an organiser.
				</div>
			<?php elseif ( 1 === count( $options ) ) : ?>
				<?php
				$onlyId  = array_key_first( $options );
				$only    = $options[ $onlyId ];
				?>
				<input type="hidden" name="payment_method" value="<?= e( $onlyId ) ?>">
				<p class="muted">
					Paying by <strong><?= e( $only['method']->label() ) ?></strong>.
					<?= e( $only['method']->description() ) ?>
				</p>
				<?php if ( '' !== $only['reason'] ) : ?>
					<div class="flash flash--notice"><?= e( $only['reason'] ) ?></div>
				<?php endif; ?>
			<?php else : ?>
				<h3>How would you like to pay?</h3>

				<?php foreach ( $options as $methodId => $option ) : ?>
					<div class="field">
						<label>
							<input type="radio" name="payment_method" value="<?= e( $methodId ) ?>"
								<?= $methodId === $firstFree ? 'checked' : '' ?>
								<?= '' !== $option['reason'] ? 'disabled' : '' ?>>
							<?= e( $option['method']->label() ) ?>
						</label>
						<p class="help" style="margin-left:24px">
							<?= e( '' !== $option['reason'] ? $option['reason'] : $option['method']->description() ) ?>
						</p>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<button type="submit" <?= ( $problems || '' === $firstFree || ! Auth::canOrder() ) ? 'disabled' : '' ?>>
				Place order
			</button>
		</form>
	</div>

<?php endif; ?>
