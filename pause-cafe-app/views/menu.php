<?php
use PauseCafe\Auth;
use PauseCafe\Csrf;
use PauseCafe\Menu;
use PauseCafe\Money;
use PauseCafe\Schedule;
use PauseCafe\Settings;

$user = Auth::user();
?>

<div class="menu-head">
	<h1><?= e( Settings::get( 'menu_heading' ) ) ?></h1>

	<?php if ( $serviceDate ) : ?>
		<p class="menu-state"><?= e( Schedule::formatDate( $serviceDate, 'l j F' ) ) ?></p>
	<?php endif; ?>
</div>

<?php if ( '' !== $blackout ) : ?>
	<div class="panel" style="text-align:center">
		<h2><?= e( $blackout ) ?></h2>
		<p class="muted">There is no lunch on <?= e( Schedule::formatDate( $serviceDate, 'j F' ) ) ?>.</p>
	</div>
<?php elseif ( ! $blocks ) : ?>
	<div class="panel" style="text-align:center">
		<p class="muted">No menu has been published yet. Please check back soon.</p>
	</div>
<?php else : ?>

	<?php foreach ( $blocks as $block ) : ?>
		<section class="location">
			<h2><?= e( $block['location']['name'] ) ?></h2>

			<ul class="dishes">
				<?php foreach ( $block['items'] as $item ) : ?>
					<?php
					$window   = $item['window'];
					$orderable = $window->isOrderable();
					$soldOut   = Menu::isSoldOut( $item );
					?>
					<li class="dish">
						<h3><?= e( $item['name'] ) ?></h3>

						<?php if ( '' !== $item['description'] ) : ?>
							<p class="dish__desc"><?= e( $item['description'] ) ?></p>
						<?php endif; ?>

						<p class="dish__price"><?= e( Money::format( (int) $item['price_cents'] ) ) ?></p>

						<?php if ( ! $orderable ) : ?>
							<p class="dish__note"><?= e( $window->message() ) ?></p>
						<?php elseif ( $soldOut ) : ?>
							<p class="dish__note">Sold out.</p>
						<?php elseif ( null !== $item['remaining'] && $item['remaining'] <= 5 ) : ?>
							<p class="dish__left">Only <?= (int) $item['remaining'] ?> left</p>
						<?php endif; ?>

						<?php if ( $orderable && ! $soldOut ) : ?>
							<?php if ( ! $user ) : ?>
								<a class="button" href="/login">Sign in to order</a>
							<?php elseif ( ! Auth::canOrder() ) : ?>
								<p class="muted">Waiting for approval.</p>
							<?php else : ?>
								<form method="post" action="/cart/add" class="stack">
									<?= Csrf::field() ?>
									<input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

									<div class="field">
										<label for="person-<?= (int) $item['id'] ?>">Name on this meal</label>
										<input type="text" id="person-<?= (int) $item['id'] ?>" name="person_name"
											value="<?= e( $user['name'] ) ?>" required>
									</div>

									<?php if ( \PauseCafe\Groups::any() ) : ?>
										<div class="field">
											<?php
											$gs = array(
												'id'    => 'group-' . (int) $item['id'],
												'value' => $user['group_name'],
											);
											include __DIR__ . '/partials/group-select.php';
											?>
										</div>
									<?php endif; ?>

									<div class="field">
										<label for="qty-<?= (int) $item['id'] ?>">Quantity</label>
										<input type="number" id="qty-<?= (int) $item['id'] ?>" name="qty" value="1" min="1"
											<?= null !== $item['remaining'] ? 'max="' . (int) $item['remaining'] . '"' : '' ?>>
									</div>

									<div class="field">
										<label for="note-<?= (int) $item['id'] ?>">Note <span class="muted">(optional)</span></label>
										<input type="text" id="note-<?= (int) $item['id'] ?>" name="note" maxlength="200"
											placeholder="e.g. no onions">
									</div>

									<button type="submit">Add to cart</button>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>

<?php endif; ?>
