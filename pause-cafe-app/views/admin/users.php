<?php
use PauseCafe\Csrf;
use PauseCafe\Money;

?>

<h1>People</h1>

<form method="get" action="/admin/users" class="field-row no-print" style="max-width:520px">
	<input type="hidden" name="page" value="">
	<input type="text" name="q" value="<?= e( $search ) ?>" placeholder="Search name, email or group">
	<button type="submit" class="button--quiet">Search</button>
</form>

<div class="table-scroll">
	<table>
		<thead>
			<tr>
				<th>Name</th>
				<th>Group</th>
				<th>Role</th>
				<th class="num">Balance</th>
				<th>Status</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $users as $row ) : ?>
				<tr>
					<td>
						<strong><?= e( $row['name'] ) ?></strong><br>
						<span class="muted"><?= e( $row['email'] ) ?></span>
					</td>
					<td><?= e( $row['group_name'] ) ?></td>
					<td><?= e( ucfirst( $row['role'] ) ) ?></td>
					<td class="num"><?= e( Money::format( (int) $row['balance_cents'] ) ) ?></td>
					<td>
						<?php if ( (int) $row['is_approved'] === 1 ) : ?>
							<span class="pill pill--open">Approved</span>
						<?php else : ?>
							<span class="pill pill--closed">Waiting</span>
						<?php endif; ?>
					</td>
					<td>
						<details>
							<summary>Manage</summary>

							<div class="panel" style="margin-top:12px">
								<h3>Wallet</h3>
								<form method="post" action="/admin/users/<?= (int) $row['id'] ?>/wallet" class="field-row">
									<?= Csrf::field() ?>
									<div>
										<label>Direction</label>
										<select name="direction">
											<option value="credit">Credit (add)</option>
											<option value="debit">Debit (take)</option>
										</select>
									</div>
									<div>
										<label>Amount</label>
										<input type="text" name="amount" placeholder="10.00" required>
									</div>
									<div>
										<label>Note</label>
										<input type="text" name="note" placeholder="Cash received">
									</div>
									<div style="align-self:end">
										<button type="submit">Apply</button>
									</div>
								</form>

								<h3 style="margin-top:24px">Account</h3>
								<form method="post" action="/admin/users/<?= (int) $row['id'] ?>">
									<?= Csrf::field() ?>

									<div class="field-row">
										<div>
											<label>Name</label>
											<input type="text" name="name" value="<?= e( $row['name'] ) ?>">
										</div>
										<div>
											<?php
											$gs = array(
												'id'    => 'group-user-' . (int) $row['id'],
												'value' => $row['group_name'],
											);
											include \PauseCafe\View::locate( 'partials/group-select' );
											?>
										</div>
										<div>
											<label>Role</label>
											<select name="role">
												<option value="member" <?= 'member' === $row['role'] ? 'selected' : '' ?>>Member</option>
												<option value="admin" <?= 'admin' === $row['role'] ? 'selected' : '' ?>>Organiser</option>
											</select>
										</div>
										<div>
											<label>New password</label>
											<input type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep">
										</div>
									</div>

									<div class="field">
										<label>
											<input type="checkbox" name="is_approved" value="1"
												<?= (int) $row['is_approved'] === 1 ? 'checked' : '' ?>>
											Approved to order
										</label>
									</div>

									<button type="submit">Save</button>
								</form>

								<form method="post" action="/admin/users/<?= (int) $row['id'] ?>/delete" style="margin-top:16px"
									onsubmit="return confirm('Delete this account? Their orders and wallet history go too.')">
									<?= Csrf::field() ?>
									<button type="submit" class="link-button">Delete account</button>
								</form>
							</div>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<div class="panel">
	<h2>Add someone</h2>
	<p class="muted">Accounts created here are approved straight away.</p>

	<form method="post" action="/admin/users/create">
		<?= Csrf::field() ?>

		<div class="field-row">
			<div>
				<label for="new-name">Name</label>
				<input type="text" id="new-name" name="name" required>
			</div>
			<div>
				<?php
				$gs = array( 'id' => 'new-group' );
				include \PauseCafe\View::locate( 'partials/group-select' );
				?>
			</div>
			<div>
				<label for="new-email">Email</label>
				<input type="email" id="new-email" name="email" required>
			</div>
			<div>
				<label for="new-password">Password</label>
				<input type="password" id="new-password" name="password" required minlength="8" autocomplete="new-password">
			</div>
			<div>
				<label for="new-role">Role</label>
				<select id="new-role" name="role">
					<option value="member">Member</option>
					<option value="admin">Organiser</option>
				</select>
			</div>
		</div>

		<button type="submit">Create account</button>
	</form>
</div>
