<?php
use PauseCafe\Auth;
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;
use PauseCafe\Wallet;

$user = Auth::user();
?>

<h1>Your account</h1>

<?php
/*
 * The wallet is optional. With it switched off the balance is hidden -- unless
 * this account still holds money, which needs to stay visible until it is
 * settled rather than quietly disappearing.
 */
$showWallet = \PauseCafe\Payments::isEnabled( 'wallet' ) || $entries;
?>

<?php if ( $showWallet ) : ?>
	<div class="panel">
		<p class="stat__label">Wallet balance</p>
		<p class="balance <?= $balance <= 0 ? 'balance--low' : '' ?>"><?= e( Money::format( $balance ) ) ?></p>
		<p class="muted">
			Top up through the church's Zeffy page, or hand cash to an organiser and they
			will add it here.
		</p>
	</div>
<?php endif; ?>

<h2>Orders</h2>

<?php if ( ! $orders ) : ?>
	<p class="muted">No orders yet.</p>
<?php else : ?>
	<div class="table-scroll">
		<table>
			<thead>
				<tr>
					<th>Order</th>
					<th>Serving</th>
					<th>Status</th>
					<th class="num">Total</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<tr>
						<td><a href="/orders/<?= (int) $order['id'] ?>">#<?= (int) $order['id'] ?></a></td>
						<td><?= e( Schedule::formatDate( $order['service_date'], 'j M Y' ) ) ?></td>
						<td>
							<span class="pill pill--<?= 'confirmed' === $order['status'] ? 'open' : 'past' ?>">
								<?= e( ucfirst( $order['status'] ) ) ?>
							</span>
							<?php if ( 'confirmed' === $order['status'] && ! \PauseCafe\Orders::isPaid( $order ) ) : ?>
								<span class="pill pill--closed">To pay</span>
							<?php endif; ?>
						</td>
						<td class="num"><?= e( Money::format( (int) $order['total_cents'] ) ) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

<?php if ( $showWallet ) : ?>

<h2>Wallet history</h2>

<?php if ( ! $entries ) : ?>
	<p class="muted">Nothing yet.</p>
<?php else : ?>
	<div class="table-scroll">
		<table>
			<thead>
				<tr>
					<th>When</th>
					<th>What</th>
					<th class="num">Amount</th>
					<th class="num">Balance</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?= e( date( 'j M Y', strtotime( $entry['created_at'] . ' UTC' ) ) ) ?></td>
						<td>
							<?= e( Wallet::humanKind( $entry['kind'] ) ) ?>
							<?php if ( '' !== $entry['note'] ) : ?>
								<br><span class="muted"><?= e( $entry['note'] ) ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?= e( Money::format( (int) $entry['delta_cents'] ) ) ?></td>
						<td class="num muted"><?= e( Money::format( (int) $entry['balance_after_cents'] ) ) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

<?php endif; ?>

<h2>Change password</h2>

<form method="post" action="/account/password" class="form-narrow">
	<?= Csrf::field() ?>

	<div class="field">
		<label for="current_password">Current password</label>
		<input type="password" id="current_password" name="current_password" required autocomplete="current-password">
	</div>

	<div class="field">
		<label for="new_password">New password</label>
		<input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
	</div>

	<button type="submit">Change password</button>
</form>
