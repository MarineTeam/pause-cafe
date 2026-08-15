<?php
/**
 * What is in the cart, as it appears in the side drawer.
 *
 * Its own file because two things render it: the layout, on every page load,
 * and /cart/add, which returns this markup as JSON so the drawer can be
 * refreshed without a round trip through a full page. Rendering it in one
 * place is what keeps those two from drifting apart.
 *
 * Caller sets $cart to Cart::detailed().
 */

use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;

$cdLines = $cart['lines'];
?>

<div class="side-cart__head">
	<h2 class="side-cart__title">Your cart</h2>
	<?php // Only does anything once the script is running, which is also the only time the drawer can be open. ?>
	<button type="button" class="side-cart__close" data-cart-close aria-label="Close the cart">&times;</button>
</div>

<?php if ( ! $cdLines ) : ?>

	<p class="muted side-cart__empty">Nothing in it yet. Add a meal and it will show up here.</p>

<?php else : ?>

	<?php if ( '' !== $cart['serviceDate'] ) : ?>
		<p class="muted side-cart__for">For <?= e( Schedule::formatDate( $cart['serviceDate'], 'l j F' ) ) ?>.</p>
	<?php endif; ?>

	<?php foreach ( $cart['problems'] as $cdProblem ) : ?>
		<div class="flash flash--error"><?= e( $cdProblem ) ?></div>
	<?php endforeach; ?>

	<ul class="side-cart__lines">
		<?php foreach ( $cdLines as $cdLine ) : ?>
			<li class="side-cart__line">
				<div class="side-cart__line-main">
					<strong><?= e( $cdLine['item']['name'] ) ?></strong>
					<?php if ( $cdLine['qty'] > 1 ) : ?>
						<span class="muted">&times;<?= (int) $cdLine['qty'] ?></span>
					<?php endif; ?>

					<?php
					/*
					 * The name is the point of the whole drawer: a parent ordering
					 * for three children needs to see whose meal is whose without
					 * opening the cart page.
					 */
					?>
					<span class="side-cart__who">
						<?= '' !== $cdLine['person_name'] ? e( $cdLine['person_name'] ) : '<em class="muted">No name yet</em>' ?>
						<?php if ( '' !== $cdLine['group_name'] ) : ?>
							<span class="muted">&middot; <?= e( $cdLine['group_name'] ) ?></span>
						<?php endif; ?>
					</span>
				</div>

				<div class="side-cart__line-side">
					<span class="side-cart__price"><?= e( Money::format( $cdLine['subtotal'] ) ) ?></span>

					<form method="post" action="/cart/remove" class="inline" data-cart-form>
						<?= Csrf::field() ?>
						<input type="hidden" name="index" value="<?= (int) $cdLine['index'] ?>">
						<button type="submit" class="link-button">Remove</button>
					</form>
				</div>

				<?php if ( $cdLine['qty'] > 1 ) : ?>
					<?php
					/*
					 * Two of the same dish is usually two different children. One
					 * line of quantity two can only carry one name, so this breaks
					 * it into a line each -- after which every one of them has its
					 * own name, group and note.
					 */
					?>
					<form method="post" action="/cart/split" class="side-cart__split" data-cart-form>
						<?= Csrf::field() ?>
						<input type="hidden" name="index" value="<?= (int) $cdLine['index'] ?>">
						<button type="submit" class="link-button">Name each one separately</button>
					</form>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<div class="side-cart__foot">
		<p class="side-cart__total">
			<span>Total</span>
			<strong><?= e( Money::format( $cart['total'] ) ) ?></strong>
		</p>

		<a class="button side-cart__checkout" href="/cart">Checkout</a>
		<button type="button" class="button button--quiet side-cart__more" data-cart-close>Keep adding</button>
	</div>

<?php endif; ?>
