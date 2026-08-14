<?php
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;

?>

<h1>Order for someone</h1>

<p class="muted">
	Placed as an organiser, so the cutoff and the wallet balance are not enforced —
	for a phone order taken after Saturday, say. The wallet is still debited, and
	portion limits still apply.
</p>

<form method="get" action="/admin/orders/new" class="field-row no-print" style="max-width:420px">
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
</form>

<?php if ( ! $items ) : ?>
	<p class="muted">Nothing on the menu for that date.</p>
<?php else : ?>

	<form method="post" action="/admin/orders/new">
		<?= Csrf::field() ?>
		<input type="hidden" name="service_date" value="<?= e( $serviceDate ) ?>">

		<div class="panel">
			<div class="field" style="max-width:420px">
				<label for="user_id">Whose account</label>
				<select id="user_id" name="user_id" required>
					<option value="">Choose someone…</option>
					<?php foreach ( $users as $person ) : ?>
						<option value="<?= (int) $person['id'] ?>">
							<?= e( $person['name'] ) ?>
							(<?= e( $person['email'] ) ?>) —
							<?= e( Money::format( (int) $person['balance_cents'] ) ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php if ( count( $methods ) > 1 ) : ?>
				<div class="field" style="max-width:420px">
					<label for="payment_method">Paying by</label>
					<select id="payment_method" name="payment_method">
						<?php foreach ( $methods as $methodId => $method ) : ?>
							<option value="<?= e( $methodId ) ?>"><?= e( $method->label() ) ?></option>
						<?php endforeach; ?>
					</select>
					<p class="help">A balance that will not cover it is not a blocker here — the order records what is owed.</p>
				</div>
			<?php endif; ?>

			<div class="field" style="max-width:420px">
				<label for="note">Note</label>
				<input type="text" id="note" name="note" placeholder="Phoned in Saturday evening">
			</div>
		</div>

		<div class="table-scroll">
			<table>
				<thead>
					<tr>
						<th>Dish</th>
						<th class="num">Qty</th>
						<th>For</th>
						<th>Group</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td>
								<strong><?= e( $item['name'] ) ?></strong><br>
								<span class="muted">
									<?= e( $item['location_name'] ) ?> ·
									<?= e( Money::format( (int) $item['price_cents'] ) ) ?>
									<?php if ( null !== $item['remaining'] ) : ?>
										· <?= (int) $item['remaining'] ?> left
									<?php endif; ?>
								</span>
							</td>
							<td class="num">
								<input type="number" name="line[<?= (int) $item['id'] ?>][qty]" value="0" min="0"
									style="max-width:80px" aria-label="Quantity of <?= e( $item['name'] ) ?>">
							</td>
							<td>
								<input type="text" name="line[<?= (int) $item['id'] ?>][person_name]"
									placeholder="Name on this meal" aria-label="Name">
							</td>
							<td>
								<?php
								$gs = array(
									'name'  => 'line[' . (int) $item['id'] . '][group_name]',
									'id'    => 'line-group-' . (int) $item['id'],
									'label' => '',
								);
								include \PauseCafe\View::locate( 'partials/group-select' );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<button type="submit">Place order</button>
		<a class="button button--quiet" href="/admin/orders">Cancel</a>
	</form>

<?php endif; ?>
