<?php
use PauseCafe\Auth;
use PauseCafe\Csrf;
use PauseCafe\Menu;
use PauseCafe\Money;
use PauseCafe\Schedule;
use PauseCafe\Settings;

$user     = Auth::user();
$multiple = count( $sections ) > 1;
$anything = false;

foreach ( $sections as $section ) {
	if ( $section['blocks'] || '' !== $section['blackout'] ) {
		$anything = true;
		break;
	}
}
?>

<div class="menu-head">
	<h1><?= e( Settings::get( 'menu_heading' ) ) ?></h1>
</div>

<?php if ( ! $anything ) : ?>

	<div class="panel" style="text-align:center">
		<p class="muted">No menu has been published yet. Please check back soon.</p>
	</div>

<?php else : ?>

	<?php foreach ( $sections as $section ) : ?>
		<?php
		if ( ! $section['blocks'] && '' === $section['blackout'] ) {
			// Nothing published on this schedule yet; with another one showing,
			// an empty heading is just noise.
			continue;
		}
		?>

		<section class="menu-section">
			<?php if ( $multiple ) : ?>
				<h2 class="menu-section__name"><?= e( $section['rules']['name'] ) ?></h2>
			<?php endif; ?>

			<?php if ( $section['serviceDate'] ) : ?>
				<p class="menu-state"><?= e( Schedule::formatDate( $section['serviceDate'], 'l j F' ) ) ?></p>
			<?php endif; ?>

			<?php if ( '' !== $section['blackout'] ) : ?>

				<div class="panel" style="text-align:center">
					<h3><?= e( $section['blackout'] ) ?></h3>
					<p class="muted">
						There is no lunch on <?= e( Schedule::formatDate( $section['serviceDate'], 'j F' ) ) ?>.
					</p>
				</div>

			<?php else : ?>

				<?php
				/*
				 * One grid for the whole week rather than one per pickup location.
				 * A location with a single dish used to get its own grid, so every
				 * card sat alone in column one and nothing was ever side by side.
				 * The location is a label on the card instead.
				 */
				$dishes = array();

				foreach ( $section['blocks'] as $block ) {
					foreach ( $block['items'] as $item ) {
						$dishes[] = array(
							'item'     => $item,
							'location' => $block['location']['name'],
						);
					}
				}
				?>

				<ul class="dishes" style="--cols: <?= (int) $columns ?>">
					<?php foreach ( $dishes as $entry ) : ?>
						<?php
						$item      = $entry['item'];
						$window    = $item['window'];
						$orderable = $window->isOrderable();
						$soldOut   = Menu::isSoldOut( $item );
						?>
						<li class="dish">
							<p class="dish__where"><?= e( $entry['location'] ) ?></p>
							<h3 class="dish__name"><?= e( $item['name'] ) ?></h3>

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
									<p class="dish__action"><a class="button" href="/login">Sign in to order</a></p>
								<?php elseif ( ! Auth::canOrder() ) : ?>
									<p class="muted">Waiting for approval.</p>
								<?php else : ?>
									<form method="post" action="/cart/add" class="dish__form">
										<?= Csrf::field() ?>
										<input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">

										<?php
										/*
										 * Name and group are prefilled from the account, so ordering
										 * for yourself is one click. The fields stay in the form --
										 * a closed <details> still submits them -- and only unfold
										 * when the meal is for somebody else.
										 */
										?>
										<details class="dish__details">
											<summary>Ordering for someone else?</summary>

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
												<label for="note-<?= (int) $item['id'] ?>">Note</label>
												<input type="text" id="note-<?= (int) $item['id'] ?>" name="note" maxlength="200"
													placeholder="e.g. no onions">
											</div>
										</details>

										<div class="dish__buy">
											<label class="sr-only" for="qty-<?= (int) $item['id'] ?>">Quantity</label>
											<input type="number" id="qty-<?= (int) $item['id'] ?>" name="qty" value="1" min="1"
												aria-label="Quantity"
												<?= null !== $item['remaining'] ? 'max="' . (int) $item['remaining'] . '"' : '' ?>>
											<button type="submit">Add to cart</button>
										</div>
									</form>
								<?php endif; ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>

			<?php endif; ?>
		</section>
	<?php endforeach; ?>

<?php endif; ?>
