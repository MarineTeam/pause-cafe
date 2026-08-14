<?php
/**
 * One dish on the menu.
 *
 * Callers set $dc before including:
 *   item      row from menu_items, with 'window' and 'remaining' attached
 *   location  the pickup location's name
 *
 * This is deliberately its own file. It is the piece a theme is most likely to
 * want to change, and a theme that overrides only this inherits the ordering
 * rules, the field resolution and the cutoff handling rather than copying them
 * — which is what stops a theme quietly falling behind the app.
 */

use PauseCafe\Auth;
use PauseCafe\Csrf;
use PauseCafe\Menu;
use PauseCafe\MenuFields;
use PauseCafe\Money;

$dcItem      = $dc['item'];
$dcLocation  = (string) ( $dc['location'] ?? '' );
$dcUser      = Auth::user();
$dcWindow    = $dcItem['window'];
$dcOrderable = $dcWindow->isOrderable();
$dcSoldOut   = Menu::isSoldOut( $dcItem );
$dcPrice     = Money::format( (int) $dcItem['price_cents'] );
?>

<li class="dish <?= $dcSoldOut || ! $dcOrderable ? 'dish--shut' : '' ?>">
	<?php if ( '' !== (string) ( $dcItem['image_path'] ?? '' ) ) : ?>
		<?php
		/*
		 * Decorative: the dish name is right underneath, so a screen reader
		 * announcing the picture as well would only say it twice.
		 */
		?>
		<img class="dish__photo" src="<?= e( $dcItem['image_path'] ) ?>" alt="" loading="lazy">
	<?php endif; ?>

	<?php if ( '' !== $dcLocation ) : ?>
		<p class="dish__where"><span class="pill pill--past"><?= e( $dcLocation ) ?></span></p>
	<?php endif; ?>

	<h3 class="dish__name"><?= e( $dcItem['name'] ) ?></h3>

	<?php if ( '' !== $dcItem['description'] ) : ?>
		<p class="dish__desc"><?= e( $dcItem['description'] ) ?></p>
	<?php endif; ?>

	<?php
	/*
	 * Why this dish in particular cannot be ordered. The week above already
	 * says whether ordering is open at all, so this only covers what differs
	 * from the week: sold out, or a dish carrying its own window.
	 */
	?>
	<?php if ( $dcSoldOut ) : ?>
		<p class="dish__state"><span class="pill pill--closed">Sold out</span></p>
	<?php elseif ( ! $dcOrderable ) : ?>
		<p class="dish__state"><span class="pill pill--upcoming"><?= e( $dcWindow->message() ) ?></span></p>
	<?php elseif ( null !== $dcItem['remaining'] && $dcItem['remaining'] <= 5 ) : ?>
		<p class="dish__state"><span class="pill pill--upcoming">Only <?= (int) $dcItem['remaining'] ?> left</span></p>
	<?php endif; ?>

	<?php if ( ! $dcOrderable || $dcSoldOut ) : ?>

		<div class="dish__foot">
			<span class="dish__price"><?= e( $dcPrice ) ?></span>
		</div>

	<?php elseif ( ! $dcUser ) : ?>

		<div class="dish__foot">
			<span class="dish__price"><?= e( $dcPrice ) ?></span>
			<a class="button" href="/login">Sign in</a>
		</div>

	<?php elseif ( ! Auth::canOrder() ) : ?>

		<div class="dish__foot">
			<span class="dish__price"><?= e( $dcPrice ) ?></span>
			<span class="muted">Waiting for approval</span>
		</div>

	<?php else : ?>

		<form method="post" action="/cart/add" class="dish__form">
			<?= Csrf::field() ?>
			<input type="hidden" name="item_id" value="<?= (int) $dcItem['id'] ?>">

			<?php
			/*
			 * Which questions get asked is set by the organiser, per site, per
			 * schedule and per dish.
			 *
			 * Anything with a value to prefill -- name and group come from the
			 * account -- folds away, so ordering for yourself stays one click. A
			 * closed <details> still submits its fields. A required question with
			 * nothing to prefill has to be answered, so it stays visible.
			 */
			$dcFields   = MenuFields::visibleFor( $dcItem );
			$dcDefaults = MenuFields::collect( $dcItem, array(), $dcUser );

			$dcUpfront = array();
			$dcFolded  = array();

			foreach ( $dcFields as $dcKey => $dcField ) {
				if ( $dcField['required'] && '' === (string) ( $dcDefaults[ $dcKey ] ?? '' ) ) {
					$dcUpfront[ $dcKey ] = $dcField;
				} else {
					$dcFolded[ $dcKey ] = $dcField;
				}
			}

			if ( $dcUpfront ) {
				$of = array(
					'fields' => $dcUpfront,
					'values' => $dcDefaults,
					'prefix' => 'up' . (int) $dcItem['id'],
				);

				include \PauseCafe\View::locate( 'partials/order-fields' );
			}
			?>

			<?php if ( $dcFolded ) : ?>
				<details class="dish__details">
					<summary>Ordering for someone else?</summary>
					<?php
					$of = array(
						'fields' => $dcFolded,
						'values' => $dcDefaults,
						'prefix' => 'fold' . (int) $dcItem['id'],
					);

					include \PauseCafe\View::locate( 'partials/order-fields' );
					?>
				</details>
			<?php endif; ?>

			<div class="dish__foot">
				<span class="dish__price"><?= e( $dcPrice ) ?></span>

				<div class="dish__buy">
					<label class="sr-only" for="qty-<?= (int) $dcItem['id'] ?>">Quantity</label>
					<input type="number" id="qty-<?= (int) $dcItem['id'] ?>" name="qty" value="1" min="1"
						aria-label="Quantity"
						<?= null !== $dcItem['remaining'] ? 'max="' . (int) $dcItem['remaining'] . '"' : '' ?>>
					<button type="submit">Add</button>
				</div>
			</div>
		</form>

	<?php endif; ?>
</li>
