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

	<?php
	/*
	 * One form for the whole cart, including the Place order button.
	 *
	 * It used to be a form per line, each with its own Update button, and the
	 * checkout button lived in a form of its own. Anything typed into a line --
	 * "no onions" against a meal -- was then thrown away unless the shopper
	 * happened to press that line's Update before checking out, which is not a
	 * thing anybody does. The note simply vanished, and only sometimes, which
	 * made it look like a display bug in the kitchen list rather than data that
	 * was never saved.
	 *
	 * With one form, every button carries every answer. Update and Remove
	 * redirect elsewhere with formaction; Place order is the form's own action.
	 */
	?>
	<form method="post" action="/checkout">
		<?= Csrf::field() ?>

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
							<div class="field-row">
								<?php
								// Same questions the dish asked, so an answer given at the
								// menu can be corrected here. Nested under this line's
								// index so every line travels in the one submission.
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
									'group'  => 'line[' . (int) $line['index'] . ']',
								);

								include \PauseCafe\View::locate( 'partials/order-fields' );
								?>

								<div class="field">
									<label for="cart-qty-<?= (int) $line['index'] ?>">Qty</label>
									<input type="number" id="cart-qty-<?= (int) $line['index'] ?>"
										name="line[<?= (int) $line['index'] ?>][qty]"
										value="<?= (int) $line['qty'] ?>" min="1" style="max-width:90px">
								</div>
							</div>
						</td>
						<td class="num"><?= e( Money::format( $line['subtotal'] ) ) ?></td>
						<td class="num">
							<?php // Carries the whole form, so nothing typed is lost on the way out. ?>
							<button type="submit" class="link-button" formaction="/cart/remove"
								name="index" value="<?= (int) $line['index'] ?>">Remove</button>

							<?php if ( $line['qty'] > 1 ) : ?>
								<?php
								/*
								 * One line can hold one name, and two of a dish is usually
								 * two children. This breaks it into a line each, so every
								 * meal gets its own name, group and note.
								 */
								?>
								<br>
								<button type="submit" class="link-button" formaction="/cart/split"
									name="index" value="<?= (int) $line['index'] ?>">Name each<br>separately</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="no-print">
		<button type="submit" class="button button--quiet" formaction="/cart/update">Update cart</button>
		<span class="help">Changing a quantity above and pressing this reworks the total.</span>
	</p>

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

			<div class="field">
				<label for="order_note">A note about the whole order <span class="muted">(optional)</span></label>
				<textarea id="order_note" name="order_note" rows="2" maxlength="500"
					placeholder="Anything about collection or the order as a whole"><?= e( $orderNote ?? '' ) ?></textarea>
				<p class="help">
					For a note about one meal — “no onions” — use the box beside that
					dish above instead. The kitchen list shows them separately.
				</p>
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
	</div>

	</form>

<?php endif; ?>
